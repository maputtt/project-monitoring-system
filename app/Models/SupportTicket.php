<?php

namespace App\Models;

use App\Enums\SupportTicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Tiket laporan masalah. Tidak pernah dihapus.
class SupportTicket extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => SupportTicketStatus::class, 'resolved_at' => 'datetime'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** Halaman asal laporan hanya dipercaya bila http(s) dan host-nya sama dengan APP_URL; selain itu null (tidak pernah dialihkan, hanya ditampilkan). */
    public static function safePageUrl(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);
        $ok = $url !== null && strlen($url) <= 2048 && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && is_string($host) && strcasecmp($host, (string) parse_url(config('app.url'), PHP_URL_HOST)) === 0;

        return $ok ? $url : null;
    }
}
