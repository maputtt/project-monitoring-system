<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Tarif tidak pernah diubah atau dihapus; koreksi berarti versi baru (PRD 6.1, FD-18).
#[Fillable(['tax_type', 'name', 'vendor_qualification', 'rate', 'effective_from'])]
class TaxRate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'effective_from' => 'date:Y-m-d',
            'effective_until' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    /** Tarif yang berlaku pada $date. Kualifikasi null hanya cocok dengan PPN, jadi PPh tanpa kualifikasi tidak menemukan apa pun. */
    public function scopeInForce(Builder $query, string $taxType, Carbon|string $date, ?string $qualification = null): void
    {
        $date = Carbon::parse($date)->toDateString();

        $query->where('tax_type', $taxType)
            ->where('vendor_qualification', $qualification)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date));
    }
}
