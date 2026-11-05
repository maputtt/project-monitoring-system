<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Tampilan waktu untuk pengguna: penyimpanan tetap UTC, tampilan memakai app.local_timezone (WIB). */
final class LocalTime
{
    /** Kolom datetime saja; kolom date murni (due_date, billing_date, ...) jangan dilewatkan agar tidak bergeser. */
    public static function format(mixed $value, bool $withTime = true): string
    {
        if (blank($value)) {
            return '—';
        }
        $local = Carbon::parse($value)->timezone(config('app.local_timezone'));

        return $withTime ? $local->format('d/m/Y H:i').' WIB' : $local->format('d/m/Y');
    }
}
