<?php

namespace App\Http\Middleware;

use App\Models\Passkey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Akun yang dinonaktifkan saat masih login langsung dikeluarkan pada request berikutnya. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $message = match (true) {
            ! $request->user() => null,
            ! $request->user()->is_active => 'Akun Anda tidak aktif. Hubungi System Admin.',
            $this->passkeySessionEnded($request) => 'Login perangkat ini berakhir. Masuk dengan password.',
            default => null,
        };
        if ($message) {
            $deviceEnded = $request->user()->is_active;
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Hanya sesi perangkat yang berakhir menjawab JSON 401; akun nonaktif tetap diarahkan ke login seperti sebelumnya.
            return $deviceEnded && $request->expectsJson() ? response()->json(['message' => $message], 401) : redirect()->route('login')->withErrors(['email' => $message]);
        }

        // Penanda online: paling sering sekali per menit; updated_at ditahan karena kolomnya ON UPDATE CURRENT_TIMESTAMP.
        $user = $request->user();
        if ($user && (! $user->last_seen_at || $user->last_seen_at->lt(now()->subSeconds(60)))) {
            DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now(), 'updated_at' => DB::raw('updated_at')]);
            $user->last_seen_at = now();
        }

        return $next($request);
    }

    /** Session yang lahir dari login perangkat (passkey) berakhir jika baris dihapus, token dicabut/diganti, atau lewat 30 hari. */
    private function passkeySessionEnded(Request $request): bool
    {
        if (! $request->session()->has('passkey_id')) {
            return false;
        }
        $pk = Passkey::where('user_id', $request->user()->id)->find($request->session()->get('passkey_id'));

        return ! $pk || ! $pk->device_token_hash || ! hash_equals($pk->device_token_hash, (string) $request->session()->get('passkey_hash'))
            || ! $pk->device_expires_at?->isFuture();
    }
}
