<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Satu-satunya notifikasi aplikasi (Story E). Judul pendek tanpa nominal; url berupa path relatif. Tidak diantrekan: Notify::send mengatur kanal dan isolasi kegagalan. */
class Notice extends Notification
{
    public function __construct(public string $title, public string $path) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'url' => $this->path];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title.' - '.config('app.name'))
            ->greeting('Halo '.$notifiable->name.',')
            ->line(addcslashes($this->title, '!#..%(../:..;=?..@[..`{..~')) // judul berisi teks bebas (kode project): netralkan sintaks Markdown (tautan, tebal, kode)
            ->action('Buka di '.config('app.name'), url($this->path))
            ->line('Email ini dikirim otomatis. Tidak ada data finansial di dalamnya; masuk ke aplikasi untuk melihat rinciannya.')
            ->salutation('Salam, tim '.config('app.name'));
    }
}
