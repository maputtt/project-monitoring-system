<?php

namespace App\Http\Controllers;

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Dashboard SYSTEM_ADMIN (Story B): pengguna online dan kesehatan sistem. Tanpa data project/finansial. */
class AdminDashboardController extends Controller
{
    public const ONLINE_MINUTES = 5;

    public const RETENTION_DAYS = 7;

    public const STATUS_WINDOW_MINUTES = 60;

    public const SLOW_AVG_MS = 1000;

    public const BAD_AVG_MS = 3000;

    public const BAD_ERROR_RATE_PCT = 5;

    public function __invoke(): View
    {
        // shortcut: prune on admin view, move to Schedule::daily when cron exists
        DB::table('request_metrics')->where('bucket_at', '<', now('UTC')->subDays(self::RETENTION_DAYS))->delete();

        $online = now()->subMinutes(self::ONLINE_MINUTES);
        $users = User::query()->orderBy('name')->get()
            ->each(fn (User $u) => $u->setAttribute('is_online', $u->last_seen_at !== null && $u->last_seen_at->gte($online)))
            ->sortBy([['is_online', 'desc'], fn ($a, $b) => ($b->last_seen_at?->timestamp ?? 0) <=> ($a->last_seen_at?->timestamp ?? 0)])
            ->values();

        $from = now('UTC')->startOfHour()->subHours(23);
        $rows = DB::table('request_metrics')->where('bucket_at', '>=', $from)
            ->selectRaw("DATE_FORMAT(bucket_at, '%Y-%m-%d %H:00:00') AS hour, SUM(request_count) AS n, SUM(duration_ms_sum) AS total, MAX(duration_ms_max) AS peak, SUM(error_5xx_count) AS errors")
            ->groupBy('hour')->get()->keyBy('hour');
        $hours = collect(range(0, 23))->map(function (int $i) use ($from, $rows) {
            $t = $from->copy()->addHours($i);
            $r = $rows->get($t->format('Y-m-d H:00:00'));

            return [
                'label' => $t->copy()->timezone(config('app.local_timezone'))->format('H:00'),
                'requests' => (int) ($r->n ?? 0),
                'avg' => $r && $r->n > 0 ? (int) round($r->total / $r->n) : 0,
                'max' => (int) ($r->peak ?? 0),
                'errors' => (int) ($r->errors ?? 0),
            ];
        })->all();
        $scale = max(1, max(array_column($hours, 'max')));

        $w = DB::table('request_metrics')->where('bucket_at', '>=', now('UTC')->subMinutes(self::STATUS_WINDOW_MINUTES))
            ->selectRaw('COALESCE(SUM(request_count), 0) AS n, COALESCE(SUM(duration_ms_sum), 0) AS total, COALESCE(SUM(error_5xx_count), 0) AS errors')->first();

        return view('dashboard-admin', ['users' => $users, 'hours' => $hours, 'scale' => $scale, 'health' => self::health((int) $w->n, (int) $w->total, (int) $w->errors),
            'ticketCounts' => [
                'NEW' => SupportTicket::where('status', SupportTicketStatus::NEW)->count(),
                'IN_PROGRESS' => SupportTicket::where('status', SupportTicketStatus::IN_PROGRESS)->count(),
            ],
        ]);
    }

    /** @return array{label: string, tone: string, requests: int, avg: int, errors: int} */
    public static function health(int $requests, int $totalMs, int $errors): array
    {
        $avg = $requests > 0 ? (int) round($totalMs / $requests) : 0;
        [$label, $tone] = match (true) {
            $requests === 0 => ['Belum ada data', 'WITHDRAWN'],
            $errors * 100 >= self::BAD_ERROR_RATE_PCT * $requests, $avg >= self::BAD_AVG_MS => ['Bermasalah', 'DELAYED'],
            $avg >= self::SLOW_AVG_MS, $errors > 0 => ['Lambat', 'REVISION_REQUIRED'],
            default => ['Stabil', 'ACTIVE'],
        };

        return compact('label', 'tone', 'requests', 'avg', 'errors');
    }
}
