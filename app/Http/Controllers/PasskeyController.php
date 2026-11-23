<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\Passkey;
use App\Support\Audit;
use App\Support\WebAuthn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Face ID/sidik jari untuk kunci aplikasi di HP (model m-banking). Semua nilai biner = base64url.
 *
 * Daftar (login, sesi sah):
 *  POST /passkeys/options  -> {challenge, rp:{id,name}, user:{id,name,displayName},
 *      pubKeyCredParams:[{type:'public-key',alg:-7},{type:'public-key',alg:-257}],
 *      authenticatorSelection:{authenticatorAttachment:'platform',residentKey:'required',userVerification:'required'},
 *      attestation:'none', timeout:60000}   (user.id acak per pendaftaran; tanpa excludeCredentials)
 *  POST /passkeys  body {id, rawId, clientDataJSON, authenticatorData, publicKey (SPKI DER dari getPublicKey()),
 *      publicKeyAlgorithm (-7|-257), name} -> {ok:true}; 422 {message} jika ditolak. Cookie pms_device diterbitkan di sini.
 *  GET /passkeys -> view 'passkeys.index' ($passkeys: id,name,created_at,last_used_at,device_expires_at)
 *  DELETE /passkeys/{id} -> back() + flash status (atau {ok:true} jika expectsJson); milik orang lain 404
 *
 * Buka kunci (belum login, cookie pms_device ada):
 *  GET /passkey/unlock-options -> 204 jika sudah login; 401 jika cookie tidak ada/tidak sah/kedaluwarsa/akun nonaktif;
 *      selain itu {challenge, csrf, rpId, allowCredentials:[{type:'public-key',id}], userVerification:'required', timeout:60000}
 *  POST /passkey/unlock body {id, rawId, clientDataJSON, authenticatorData, signature, userHandle}
 *      -> {csrf, redirect}; 401 tanpa perangkat sah, 422 {message} jika assertion ditolak. Klien menulis ulang meta csrf dan input _token.
 */
class PasskeyController extends Controller
{
    public function index(Request $request): View
    {
        return view('passkeys.index', ['passkeys' => $request->user()->passkeys()->orderBy('id')->get(['id', 'name', 'created_at', 'last_used_at', 'device_expires_at'])]);
    }

    /** Sesi hasil buka kunci tidak boleh mendaftarkan passkey: token 30 hari akan terbit ulang dan melewati batas absolut. */
    private function unlockSessionRefusal(Request $request): ?JsonResponse
    {
        if (WebAuthn::canRegister($request)) {
            return null;
        }

        return response()->json(['message' => $request->session()->has('passkey_unlocked') ? 'Masuk dengan password untuk mendaftarkan perangkat.' : 'Masuk ulang dengan password untuk mendaftarkan perangkat.'], 403);
    }

    public function options(Request $request): JsonResponse
    {
        if ($refusal = $this->unlockSessionRefusal($request)) {
            return $refusal;
        }

        return response()->json([
            'challenge' => WebAuthn::newChallenge($request, 'passkey_register'),
            'rp' => ['id' => WebAuthn::rpId(), 'name' => config('app.name')],
            'user' => ['id' => WebAuthn::b64(random_bytes(32)), 'name' => $request->user()->email, 'displayName' => $request->user()->name],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'residentKey' => 'required', 'userVerification' => 'required'],
            'attestation' => 'none',
            'timeout' => 60000,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($refusal = $this->unlockSessionRefusal($request)) {
            return $refusal;
        }
        $d = $request->validate([
            'id' => ['required', 'string', 'max:255'], 'rawId' => ['required', 'string'],
            'clientDataJSON' => ['required', 'string'], 'authenticatorData' => ['required', 'string'],
            'publicKey' => ['required', 'string'], 'publicKeyAlgorithm' => ['required', 'integer', 'in:-7,-257'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        try {
            $challenge = WebAuthn::pullChallenge($request, 'passkey_register');
            $count = WebAuthn::verifyRegistration($d['clientDataJSON'], $d['authenticatorData'], $challenge, $d['id']);
            $pem = WebAuthn::spkiToPem($d['publicKey'], $d['publicKeyAlgorithm']);
            if ($d['rawId'] !== $d['id']) {
                throw new RuntimeException('Data kredensial tidak cocok.');
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if (Passkey::where('credential_id', $d['id'])->exists()) {
            return response()->json(['message' => 'Perangkat ini sudah terdaftar.'], 422);
        }

        try {
            DB::transaction(function () use ($request, $d, $pem, $count) {
                $pk = $request->user()->passkeys()->create(['credential_id' => $d['id'], 'public_key' => $pem, 'algorithm' => $d['publicKeyAlgorithm'], 'sign_count' => $count, 'name' => $d['name']]);
                WebAuthn::issueDevice($pk);
                WebAuthn::linkPush($request, $pk);
                Audit::record(AuditAction::UPDATE, 'USER', $request->user()->id, reason: 'Tambah passkey: '.$pk->name);
            });
        } catch (UniqueConstraintViolationException) { // pendaftaran ganda bersamaan lolos exists() di atas
            return response()->json(['message' => 'Perangkat ini sudah terdaftar.'], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $request->user()->passkeys()->findOrFail($id)->remove();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('status', 'Perangkat dihapus.');
    }

    public function unlockOptions(Request $request): JsonResponse
    {
        if (Auth::check()) {
            return response()->json(null, 204);
        }
        if (! $pk = WebAuthn::validDevice($request)) {
            return response()->json(['message' => 'Perangkat tidak dikenali.'], 401);
        }

        return response()->json([
            'challenge' => WebAuthn::newChallenge($request, 'passkey_unlock'),
            'csrf' => csrf_token(), // token halaman basi setelah session kedaluwarsa; klien memakai ini untuk POST unlock
            'rpId' => WebAuthn::rpId(),
            'allowCredentials' => [['type' => 'public-key', 'id' => $pk->credential_id]],
            'userVerification' => 'required',
            'timeout' => 60000,
        ]);
    }

    public function unlock(Request $request): JsonResponse
    {
        if (Auth::check()) {
            return response()->json(['csrf' => csrf_token(), 'redirect' => url('/dashboard')]);
        }
        if (! $pk = WebAuthn::validDevice($request)) {
            return response()->json(['message' => 'Perangkat tidak dikenali.'], 401);
        }
        $d = $request->validate([
            'id' => ['required', 'string'], 'clientDataJSON' => ['required', 'string'],
            'authenticatorData' => ['required', 'string'], 'signature' => ['required', 'string'],
        ]);

        try {
            $challenge = WebAuthn::pullChallenge($request, 'passkey_unlock'); // dihanguskan dulu: satu kali pakai walau verifikasi gagal
            // Hanya kredensial milik perangkat pada cookie ini yang boleh membuka kunci.
            if (! hash_equals($pk->credential_id, $d['id'])) {
                throw new RuntimeException('Kredensial tidak cocok dengan perangkat.');
            }
            $count = WebAuthn::verifyAssertion($pk, $d['clientDataJSON'], $d['authenticatorData'], $d['signature'], $challenge);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Token perangkat tidak diperpanjang di sini: 30 hari absolut sejak login password.
        $pk->forceFill(['sign_count' => $count, 'last_used_at' => now()])->save();
        Auth::login($pk->user);
        $request->session()->regenerate();
        $pk->user->forceFill(['last_login_at' => now()])->save();
        $request->session()->put(['passkey_id' => $pk->id, 'passkey_hash' => $pk->device_token_hash, 'passkey_unlocked' => true]); // bukan passkey_unlock: itu kunci challenge
        WebAuthn::linkPush($request, $pk);

        return response()->json(['csrf' => csrf_token(), 'redirect' => $request->session()->pull('url.intended', url('/dashboard'))]);
    }
}
