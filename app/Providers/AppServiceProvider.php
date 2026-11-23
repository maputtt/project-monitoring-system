<?php

namespace App\Providers;

use App\Models\Project;
use App\Support\WebAuthn;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Minishlink\WebPush\WebPush;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Dibind (bukan singleton) agar antrean tiap pengiriman bersih dan tes bisa mengganti dengan mock.
        $this->app->bind(WebPush::class, fn () => new WebPush(['VAPID' => [
            'subject' => config('services.webpush.subject'), 'publicKey' => config('services.webpush.public_key'), 'privateKey' => config('services.webpush.private_key'),
        ]]));
    }

    public function boot(): void
    {
        // Email reset password berbahasa Indonesia.
        ResetPassword::toMailUsing(fn (object $user, string $token) => (new MailMessage)
            ->subject('Reset password '.config('app.name'))
            ->greeting('Halo '.$user->name.',')
            ->line('Kami menerima permintaan reset password untuk akun Anda.')
            ->action('Buat password baru', route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->line('Tautan ini berlaku 60 menit dan hanya bisa dipakai sekali.')
            ->line('Jika Anda tidak memintanya, abaikan email ini. Password Anda tidak berubah.')
            ->salutation('Salam, tim '.config('app.name')));

        // Cek kunci HP dipanggil tiap aplikasi kembali ke layar; kunci per perangkat (id passkey di cookie) agar satu IP kantor tidak berbagi jatah.
        $device = fn (Request $request) => explode('|', (string) $request->cookie(WebAuthn::COOKIE))[0] ?: $request->ip();
        RateLimiter::for('passkey-options', fn (Request $request) => Limit::perMinute(20)->by($device($request)));
        RateLimiter::for('passkey-unlock', fn (Request $request) => Limit::perMinute(10)->by($device($request))); // sebelumnya 10/menit per IP: satu Wi-Fi kantor berbagi jatah

        // Setiap route ber-parameter {project} hanya menemukan project yang boleh dilihat user.
        // Project di luar divisi atau assignment menjawab 404, sama seperti project yang tidak ada.
        Route::bind('project', fn (string $id) => Project::query()->visibleTo(auth()->user() ?? abort(401))->findOrFail($id));
    }
}
