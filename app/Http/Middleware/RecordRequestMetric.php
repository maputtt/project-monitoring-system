<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Agregat request per menit (UTC) untuk dashboard admin. Dicatat di terminate(); kegagalan pencatatan tidak boleh mengganggu request. */
class RecordRequestMetric
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $ms = (int) max(0, round((microtime(true) - (float) $request->server('REQUEST_TIME_FLOAT', microtime(true))) * 1000));
            $error = $response->getStatusCode() >= 500 ? 1 : 0;
            DB::statement(
                'INSERT INTO request_metrics (bucket_at, request_count, duration_ms_sum, duration_ms_max, error_5xx_count) VALUES (?, 1, ?, ?, ?) '
                .'ON DUPLICATE KEY UPDATE request_count = request_count + 1, duration_ms_sum = duration_ms_sum + ?, duration_ms_max = GREATEST(duration_ms_max, ?), error_5xx_count = error_5xx_count + ?',
                [now('UTC')->format('Y-m-d H:i:00'), $ms, $ms, $error, $ms, $ms, $error],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
