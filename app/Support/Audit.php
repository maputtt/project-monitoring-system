<?php

namespace App\Support;

use App\Enums\AuditAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Satu-satunya jalan menulis audit_logs (PRD 10.3). Tabelnya append-only.
 * Panggil di dalam transaksi yang sama dengan perubahannya, supaya keduanya berhasil atau gagal bersama.
 */
final class Audit
{
    public static function record(
        AuditAction $action,
        string $entityType,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?int $userId = null,
    ): void {
        $request = request();

        DB::table('audit_logs')->insert([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action->value,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'data_before' => $before === null ? null : json_encode($before),
            'data_after' => $after === null ? null : json_encode($after),
            'reason' => $reason,
            'request_id' => self::requestId(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /** Satu id per request, sehingga beberapa baris audit dari aksi yang sama bisa dikaitkan. */
    private static function requestId(): string
    {
        $request = request();
        if (! $request->attributes->has('request_id')) {
            $request->attributes->set('request_id', $request->header('X-Request-Id') ?: (string) Str::uuid());
        }

        return $request->attributes->get('request_id');
    }
}
