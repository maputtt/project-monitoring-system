<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\Notice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Notifikasi (Story E). recipients() adalah SATU-SATUNYA penentu penerima; send() tidak menerima daftar user dari luar.
 * Jaminan PM: tidak pernah sampai ke user di luar divisi atau assignment project (setiap penerima lolos isVisibleTo / policy view).
 * Dipanggil setelah DB::transaction selesai; kegagalan kirim tidak boleh menggagalkan aksi.
 */
class Notify
{
    /** @return Collection<int, User> */
    public static function recipients(string $event, Project|SupportTicket $subject, User $actor): Collection
    {
        $users = match ($event) {
            'awaiting' => User::query()->where('is_active', true)->where('role', Role::MANAGER_GM)->where('department', $subject->department)->get(), // menunggu keputusan
            'decided' => $subject->usablePics()->get(), // disetujui/perlu revisi: PIC yang bisa bekerja di project ini
            'ticket_created' => User::query()->where('is_active', true)->where('role', Role::SYSTEM_ADMIN)->get(),
            'ticket_updated' => User::query()->where('is_active', true)->whereKey($subject->reporter_id)->get(),
            default => throw new InvalidArgumentException("Event notifikasi tidak dikenal: $event"),
        };

        return $users->reject(fn (User $u) => $u->is($actor))
            ->filter(fn (User $u) => $subject instanceof Project ? $subject->isVisibleTo($u) : Gate::forUser($u)->allows('view', $subject))
            ->values();
    }

    /** Lonceng ditulis sinkron; email (bila tanpa push) dikirim setelah respons lewat defer(). Judul tanpa nominal (tidak ada angka finansial di email). */
    public static function send(string $event, Project|SupportTicket $subject, User $actor, string $title): void
    {
        $path = $subject instanceof Project ? route('projects.show', $subject, absolute: false) : route('support-tickets.show', $subject, absolute: false);
        $notice = new Notice($title, $path);
        $users = self::recipients($event, $subject, $actor);

        foreach ($users as $user) {
            try {
                $user->notifyNow($notice, ['database']);
            } catch (Throwable $e) {
                report($e);
            }
        }

        defer(function () use ($users, $notice) {
            foreach ($users as $user) {
                try {
                    self::hasPush($user) ? self::push($user, $notice) : $user->notifyNow($notice, ['mail']);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /** Kunci VAPID kosong dianggap tanpa push (user tetap diemail, bukan tidak diberi tahu sama sekali). */
    private static function hasPush(User $user): bool
    {
        return config('services.webpush.public_key') && config('services.webpush.private_key') && $user->pushSubscriptions()->exists();
    }

    /** Payload hanya judul dan path relatif (tanpa nominal). Langganan 404/410 dihapus; kegagalan lain ditangani pemanggil (report). */
    private static function push(User $user, Notice $notice): void
    {
        $push = app(WebPush::class);
        $payload = json_encode(['title' => $notice->title, 'url' => $notice->path]);
        foreach ($user->pushSubscriptions as $s) {
            $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->public_key, 'authToken' => $s->auth_token, 'contentEncoding' => 'aes128gcm']), $payload);
        }
        foreach ($push->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }
}
