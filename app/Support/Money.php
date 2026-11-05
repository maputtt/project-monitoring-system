<?php

namespace App\Support;

use BcMath\Number;
use InvalidArgumentException;
use RoundingMode;

/**
 * Satu-satunya tempat aritmetika uang (PRD FD-03 dan FD-09).
 *
 * Nilai masuk dan keluar sebagai string desimal, misalnya "671151608.00", sama seperti yang
 * dikembalikan kolom DECIMAL. Tidak ada float. Pembulatan half-up ke dua desimal dilakukan
 * sekali di akhir tiap operasi; hasil antara dihitung dengan presisi lebih tinggi.
 */
final class Money
{
    private const INTERMEDIATE_SCALE = 10;

    public static function round(Number|string|int $value): string
    {
        $rounded = self::number($value)->round(2, RoundingMode::HalfAwayFromZero);

        return bcadd((string) $rounded, '0', 2); // selalu dua desimal, termasuk "10" menjadi "10.00"
    }

    public static function add(Number|string|int ...$values): string
    {
        $sum = new Number('0');
        foreach ($values as $value) {
            $sum = $sum->add(self::number($value));
        }

        return self::round($sum);
    }

    public static function sub(Number|string|int $a, Number|string|int $b): string
    {
        return self::round(self::number($a)->sub(self::number($b)));
    }

    /** amount × pct / 100, misalnya target margin atau PPN atas DPP. */
    public static function percentOf(Number|string|int $amount, Number|string|int $pct): string
    {
        return self::round(self::number($amount)->mul(self::number($pct))->div('100', self::INTERMEDIATE_SCALE));
    }

    /** part / whole × 100 half-up dengan $scale desimal (default empat, sesuai kolom persen). Whole nol menghasilkan nol (tanpa pembagian nol). */
    public static function ratioPct(Number|string|int $part, Number|string|int $whole, int $scale = 4): string
    {
        if (self::compare($whole, 0) === 0) {
            return bcadd('0', '0', $scale);
        }
        $pct = self::number($part)->mul('100')->div(self::number($whole), self::INTERMEDIATE_SCALE)->round($scale, RoundingMode::HalfAwayFromZero);

        return bcadd((string) $pct, '0', $scale);
    }

    /** Menambah pajak: net × (1 + rate/100). Dipakai margin base kontrak EXCLUSIVE_PPN. */
    public static function addTax(Number|string|int $net, Number|string|int $ratePct): string
    {
        return self::round(self::number($net)->mul(self::factor($ratePct)));
    }

    /** Mengeluarkan pajak: gross / (1 + rate/100). Dipakai DPP dari nilai SPK termasuk PPN. */
    public static function removeTax(Number|string|int $gross, Number|string|int $ratePct): string
    {
        return self::round(self::number($gross)->div(self::factor($ratePct), self::INTERMEDIATE_SCALE));
    }

    /**
     * Membagi total menurut persentase. Bagian terakhir menyerap selisih pembulatan,
     * sehingga jumlah seluruh bagian selalu tepat sama dengan total.
     *
     * @param  list<string|int>  $percentages
     * @return list<string>
     */
    public static function allocate(Number|string|int $total, array $percentages): array
    {
        if ($percentages === []) {
            throw new InvalidArgumentException('Persentase tidak boleh kosong.');
        }

        $total = self::round($total);
        $parts = [];
        $allocated = '0.00';
        $last = array_key_last($percentages);

        foreach ($percentages as $i => $pct) {
            $part = $i === $last ? self::sub($total, $allocated) : self::percentOf($total, $pct);
            $allocated = self::add($allocated, $part);
            $parts[] = $part;
        }

        return $parts;
    }

    /** -1, 0, atau 1. */
    public static function compare(Number|string|int $a, Number|string|int $b): int
    {
        return self::number($a)->compare(self::number($b));
    }

    /** "671151608.00" menjadi "Rp671.151.608,00". Berbasis string agar aman untuk angka 18 digit. */
    public static function format(Number|string|int|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        $rounded = self::round($value);
        $negative = str_starts_with($rounded, '-');
        [$whole, $cents] = explode('.', ltrim($rounded, '-'));
        $grouped = strrev(implode('.', str_split(strrev($whole), 3)));

        return ($negative ? '-' : '').'Rp'.$grouped.','.$cents;
    }

    /** Persen untuk tampilan: "12.5000" menjadi "12,5", "11.00" menjadi "11". Nilai isian form tetap memakai titik. */
    public static function pct(string $value): string
    {
        return str_replace('.', ',', str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value);
    }

    private static function number(Number|string|int $value): Number
    {
        return $value instanceof Number ? $value : new Number((string) $value);
    }

    private static function factor(Number|string|int $ratePct): Number
    {
        return self::number($ratePct)->div('100', self::INTERMEDIATE_SCALE)->add('1');
    }
}
