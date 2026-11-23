<?php

namespace App\Http\Controllers;

use App\Models\Passkey;
use App\Support\WebAuthn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 60;

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Dihitung per email dan IP, supaya satu orang tidak mengunci akun orang lain dari jaringan berbeda.
        $throttleKey = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan masuk. Coba lagi dalam '.RateLimiter::availableIn($throttleKey).' detik.',
            ]);
        }

        // Akun nonaktif ditolak dengan pesan yang sama, agar status akun tidak bisa ditebak dari luar.
        if (! Auth::attempt($credentials + ['is_active' => true])) {
            RateLimiter::hit($throttleKey, self::LOCK_SECONDS);

            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate(); // mencegah session fixation
        $request->session()->put('password_login_at', now()->timestamp); // jendela 10 menit untuk mendaftarkan passkey; buka kunci tidak mengisinya

        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        // HP yang cookie-nya menamai passkey milik user ini: login password menerbitkan ulang token 30 hari tanpa ceremony baru.
        if (($device = WebAuthn::cookieRow($request)) && $device->user_id === $user->id) {
            WebAuthn::issueDevice($device);
        }

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        DB::table('users')->where('id', $request->user()?->id)->update(['last_seen_at' => null, 'updated_at' => DB::raw('updated_at')]);
        // Hanya langganan perangkat ini milik user ini; perangkat lain tidak tersentuh.
        DB::table('push_subscriptions')->where('endpoint', $request->session()->get('push_endpoint'))->where('user_id', $request->user()?->id)->delete();
        // Token perangkat dicabut; cookie dibiarkan agar login password berikutnya di HP ini menerbitkannya lagi.
        Passkey::where('id', $request->session()->get('passkey_id'))->where('user_id', $request->user()?->id)->update(['device_token_hash' => null, 'device_expires_at' => null]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
