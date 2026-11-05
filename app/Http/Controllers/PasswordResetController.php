<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    // Jawaban yang sama untuk email terdaftar, tidak terdaftar, dan akun nonaktif.
    private const SENT_MESSAGE = 'Jika email terdaftar dan akunnya aktif, tautan reset password sudah dikirim. Tautan berlaku 60 menit.';

    public function showRequest(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        // Hasilnya sengaja tidak diperiksa: user tak dikenal, akun nonaktif, dan permintaan terlalu sering
        // semuanya menjawab sama, supaya keberadaan akun tidak bisa ditebak.
        Password::sendResetLink(['email' => $request->string('email')->value(), 'is_active' => true]);

        return back()->with('status', self::SENT_MESSAGE);
    }

    public function showReset(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($data + ['is_active' => true], function (User $user, string $password) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password_hash' => $password])->save(); // cast "hashed" yang meng-hash
                DB::table('sessions')->where('user_id', $user->id)->delete(); // keluarkan sesi lama di perangkat lain
                Audit::record(AuditAction::UPDATE, 'USER', $user->id, reason: 'Reset password', userId: $user->id);
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Tautan reset tidak berlaku atau sudah kedaluwarsa. Minta tautan baru.']);
        }

        return redirect()->route('login')->with('status', 'Password berhasil diganti. Silakan masuk dengan password baru.');
    }
}
