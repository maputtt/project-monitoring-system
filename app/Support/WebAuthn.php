<?php

namespace App\Support;

use App\Models\Passkey;
use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Verifikasi WebAuthn seperlunya (attestation "none", ES256/RS256, UP+UV wajib) memakai openssl; tanpa dependency.
 * Juga memegang token perangkat 30 hari (cookie terenkripsi pms_device = "{passkey_id}|{token}").
 */
class WebAuthn
{
    public const COOKIE = 'pms_device';

    public const DEVICE_DAYS = 30;

    public const CHALLENGE_SECONDS = 300;

    /** Pendaftaran passkey hanya sampai 10 menit setelah login password di sesi ini, dan tidak dari sesi hasil buka kunci (keputusan PM 2026-11-19). */
    public static function canRegister(Request $request): bool
    {
        return ! $request->session()->has('passkey_unlocked')
            && now()->timestamp - (int) $request->session()->get('password_login_at', 0) <= 600;
    }

    public static function rpId(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    public static function origin(): string
    {
        $p = parse_url((string) config('app.url'));

        return $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        $bin = base64_decode(strtr($s, '-_', '+/'), true);

        return $bin === false ? throw new RuntimeException('Data tidak valid.') : $bin;
    }

    /** Challenge sekali pakai, hangus setelah 5 menit; disimpan di session. */
    public static function newChallenge(Request $request, string $key): string
    {
        $challenge = self::b64(random_bytes(32));
        $request->session()->put($key, ['v' => $challenge, 't' => now()->timestamp]);

        return $challenge;
    }

    public static function pullChallenge(Request $request, string $key): string
    {
        $c = $request->session()->pull($key);

        return is_array($c) && now()->timestamp - $c['t'] <= self::CHALLENGE_SECONDS ? $c['v'] : throw new RuntimeException('Tantangan kedaluwarsa. Ulangi.');
    }

    /** Kunci publik SPKI DER (base64url) menjadi PEM; jenis kunci harus cocok dengan algoritma COSE. */
    public static function spkiToPem(string $spki, int $alg): string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(self::unb64($spki)), 64, "\n")."-----END PUBLIC KEY-----\n";
        $d = ($key = @openssl_pkey_get_public($pem)) ? openssl_pkey_get_details($key) : null;
        $ok = $d && match ($alg) {
            -7 => $d['type'] === OPENSSL_KEYTYPE_EC && ($d['ec']['curve_name'] ?? '') === 'prime256v1',
            -257 => $d['type'] === OPENSSL_KEYTYPE_RSA && $d['bits'] >= 2048,
            default => false,
        };

        return $ok ? $pem : throw new RuntimeException('Kunci publik tidak valid.');
    }

    /** Pendaftaran: kembalikan sign_count awal. $id = credential id (base64url). */
    public static function verifyRegistration(string $clientData, string $authData, string $challenge, string $id): int
    {
        self::checkClientData($clientData, 'webauthn.create', $challenge);
        [$flags, $count] = self::checkAuthData($authData = self::unb64($authData));
        // AT: data kredensial hadir; id di dalamnya harus sama dengan id yang dikirim.
        $len = strlen($authData) >= 55 ? unpack('n', $authData, 53)[1] : 0;
        if (! ($flags & 0x40) || $len === 0 || ! hash_equals(substr($authData, 55, $len), self::unb64($id))) {
            throw new RuntimeException('Data kredensial tidak cocok.');
        }

        return $count;
    }

    /** Login: kembalikan sign_count baru. Aturan counter: jika salah satunya bukan 0, harus naik (passkey iCloud selalu 0). */
    public static function verifyAssertion(Passkey $pk, string $clientData, string $authData, string $signature, string $challenge): int
    {
        $rawClient = self::checkClientData($clientData, 'webauthn.get', $challenge);
        [, $count] = self::checkAuthData($authData = self::unb64($authData));

        if (($count > 0 || $pk->sign_count > 0) && $count <= $pk->sign_count) {
            throw new RuntimeException('Counter perangkat mundur.');
        }
        if (openssl_verify($authData.hash('sha256', $rawClient, true), self::unb64($signature), $pk->public_key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Tanda tangan tidak valid.');
        }

        return $count;
    }

    private static function checkClientData(string $clientData, string $type, string $challenge): string
    {
        $raw = self::unb64($clientData);
        $json = json_decode($raw, true);
        if (! is_array($json) || ($json['type'] ?? null) !== $type || ! is_string($json['challenge'] ?? null)
            || ! hash_equals($challenge, $json['challenge']) || ($json['origin'] ?? null) !== self::origin() || ($json['crossOrigin'] ?? false) === true) {
            throw new RuntimeException('Data klien tidak valid.');
        }

        return $raw;
    }

    /** @return array{int, int} flags dan sign_count */
    private static function checkAuthData(string $authData): array
    {
        if (strlen($authData) < 37 || ! hash_equals(hash('sha256', self::rpId(), true), substr($authData, 0, 32))) {
            throw new RuntimeException('Data autentikator tidak valid.');
        }
        $flags = ord($authData[32]);
        if (($flags & 0x05) !== 0x05) { // UP dan UV wajib
            throw new RuntimeException('Verifikasi Face ID/sidik jari wajib.');
        }

        return [$flags, unpack('N', $authData, 33)[1]];
    }

    // --- Token perangkat -------------------------------------------------------------------------

    /** Baris passkey yang dinamai cookie (tanpa memeriksa token). */
    public static function cookieRow(Request $request): ?Passkey
    {
        [$id] = explode('|', (string) $request->cookie(self::COOKIE), 2) + [null];

        return ctype_digit((string) $id) ? Passkey::with('user')->find((int) $id) : null;
    }

    /** Perangkat yang token, masa berlaku, dan akunnya masih sah; null jika tidak. */
    public static function validDevice(Request $request): ?Passkey
    {
        $pk = self::cookieRow($request);
        $token = explode('|', (string) $request->cookie(self::COOKIE), 2)[1] ?? '';

        return $pk && $pk->device_token_hash && hash_equals($pk->device_token_hash, hash('sha256', $token))
            && $pk->device_expires_at?->isFuture() && $pk->user->is_active ? $pk : null;
    }

    /** Id passkey HP ini untuk menautkan langganan push: dari session, atau cookie perangkat milik user yang sama dan masih sah. */
    public static function currentDeviceId(Request $request): ?int
    {
        $pk = self::validDevice($request);

        return $request->session()->get('passkey_id') ?? ($pk?->user_id === $request->user()->id ? $pk->id : null);
    }

    /** Tautkan langganan push session ini (milik user yang sama) ke passkey: menghapus passkey ikut menghapus langganan HP itu. */
    public static function linkPush(Request $request, Passkey $pk): void
    {
        if ($endpoint = $request->session()->get('push_endpoint')) {
            PushSubscription::where('endpoint', $endpoint)->where('user_id', $pk->user_id)->update(['passkey_id' => $pk->id]);
        }
    }

    /** Terbitkan token baru 30 hari (tidak pernah diperpanjang selama tidak diterbitkan ulang) dan tautkan ke session. */
    public static function issueDevice(Passkey $pk): void
    {
        $token = Str::random(64);
        $pk->forceFill(['device_token_hash' => hash('sha256', $token), 'device_expires_at' => now()->addDays(self::DEVICE_DAYS)])->save();
        session()->put(['passkey_id' => $pk->id, 'passkey_hash' => $pk->device_token_hash]);
        // Cookie hidup lebih lama dari token hanya untuk mengenali perangkat; batas sebenarnya ada di device_expires_at.
        Cookie::queue(Cookie::make(self::COOKIE, $pk->id.'|'.$token, 400 * 24 * 60, secure: app()->environment('local', 'testing') ? null : true));
    }
}
