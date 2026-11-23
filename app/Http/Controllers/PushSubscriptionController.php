<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Support\WebAuthn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Langganan web push perangkat user yang sedang login (Story D). Endpoint yang sama dipindahkan ke user terbaru (perangkat dipakai bergantian). */
class PushSubscriptionController extends Controller
{
    /** Host layanan push browser yang dikenal; selain itu ditolak (cegah SSRF saat server mengirim ke endpoint). */
    private static function allowedHost(string $url): bool
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));

        return ($p['scheme'] ?? '') === 'https' && ! isset($p['user']) && ($p['port'] ?? 443) === 443
            && (in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com'], true)
                || str_ends_with($host, '.push.apple.com') || str_ends_with($host, '.notify.windows.com'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'starts_with:https://', 'max:500', fn ($a, $v, $fail) => self::allowedHost($v) || $fail('Endpoint push tidak dikenal.')],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            ['user_id' => $request->user()->id, 'passkey_id' => WebAuthn::currentDeviceId($request), 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth']],
        );
        $request->session()->put('push_endpoint', $data['endpoint']);

        return response()->json(null, 204);
    }
}
