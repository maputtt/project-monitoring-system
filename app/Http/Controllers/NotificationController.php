<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Lonceng notifikasi (Story E): hanya notifikasi milik sendiri; tidak ada hapus. */
class NotificationController extends Controller
{
    /** Tandai dibaca lalu buka tautannya. Notifikasi milik orang lain 404. Hanya path relatif satu '/' yang diikuti (cegah open redirect). */
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        $url = (string) ($notification->data['url'] ?? '');

        return redirect(preg_match('#^/(?![/\\\\])#', $url) === 1 ? $url : route('dashboard', absolute: false));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
