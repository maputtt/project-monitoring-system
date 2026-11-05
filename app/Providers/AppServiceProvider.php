<?php

namespace App\Providers;

use App\Models\Project;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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

        // Setiap route ber-parameter {project} hanya menemukan project yang boleh dilihat user.
        // Project di luar divisi atau assignment menjawab 404, sama seperti project yang tidak ada.
        Route::bind('project', fn (string $id) => Project::query()->visibleTo(auth()->user() ?? abort(401))->findOrFail($id));
    }
}
