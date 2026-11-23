<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

// Satu perangkat terdaftar untuk Face ID/sidik jari. Boleh dihapus: bukan data historis.
class Passkey extends Model
{
    protected $guarded = [];

    protected $hidden = ['public_key', 'device_token_hash'];

    protected function casts(): array
    {
        return [
            'algorithm' => 'integer',
            'sign_count' => 'integer',
            'device_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Hapus perangkat: login perangkat dan session yang sedang berjalan ikut berakhir (dicek EnsureUserIsActive). Dicatat di audit user. */
    public function remove(): void
    {
        DB::transaction(function () {
            Audit::record(AuditAction::UPDATE, 'USER', $this->user_id, reason: 'Hapus passkey: '.$this->name);
            $this->delete();
        });
    }
}
