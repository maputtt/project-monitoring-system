<?php

namespace App\Support;

use App\Models\Project;
use App\Models\TaxRate;
use Illuminate\Validation\ValidationException;

/**
 * Pajak satu invoice (PRD 5.5 dan 6.3), dipakai bersama oleh submit dan pratinjau supaya angkanya pasti sama.
 *
 * Persentase termin adalah persentase nilai SPK awal (initial_contract_value), jadi termin PLANNED tidak ikut menyusut setelah koreksi nilai kontrak.
 * Bagian termin = Money::allocate(nilai kontrak, persentase): INCLUSIVE_PPN = subtotal (bruto), EXCLUSIVE_PPN dan NON_PPN = DPP. Jumlah seluruh subtotal tepat sama dengan nilai kontrak.
 * Termin bukan terakhir: INCLUSIVE DPP = bagian / (1 + PPN), ppn = subtotal - DPP; EXCLUSIVE PPN = bagian x tarif PPN, subtotal = DPP + PPN; NON_PPN PPN 0.
 * Termin terakhir menyerap seluruh selisih kumulatif (DPP, PPN, subtotal): angka turunannya = nilai untuk total kontrak - jumlah termin sebelumnya. PPh = DPP x tarif PPh, diterima bersih = subtotal - PPh.
 */
final class InvoiceTax
{
    /**
     * Murni aritmetika, tanpa database.
     *
     * @param  list<string>  $percentages  persentase semua termin project urut sequence_number
     * @param  int  $index  posisi termin ini di $percentages (mulai 0)
     * @return array{dpp_amount: string, ppn_amount: string, subtotal_amount: string, pph_amount: string, net_received_amount: string}
     */
    public static function calculate(string $contractValue, string $taxMode, array $percentages, int $index, string $ppnRate, string $pphRate): array
    {
        $shares = Money::allocate($contractValue, $percentages);
        // Angka turunan share: INCLUSIVE = DPP, EXCLUSIVE = PPN, NON_PPN = 0.
        $derive = fn (string $v) => match ($taxMode) {
            'INCLUSIVE_PPN' => Money::removeTax($v, $ppnRate),
            'EXCLUSIVE_PPN' => Money::percentOf($v, $ppnRate),
            default => '0.00',
        };
        $x = $index === array_key_last($percentages)
            ? Money::sub($derive(Money::round($contractValue)), Money::add(...array_map($derive, array_slice($shares, 0, $index))))
            : $derive($shares[$index]);

        if ($taxMode === 'INCLUSIVE_PPN') {
            $subtotal = $shares[$index];
            $dpp = $x;
            $ppn = Money::sub($subtotal, $dpp);
        } else {
            $dpp = $shares[$index];
            $ppn = $x;
            $subtotal = Money::add($dpp, $ppn);
        }
        $pph = Money::percentOf($dpp, $pphRate);

        return ['dpp_amount' => $dpp, 'ppn_amount' => $ppn, 'subtotal_amount' => $subtotal, 'pph_amount' => $pph, 'net_received_amount' => Money::sub($subtotal, $pph)];
    }

    /**
     * Tarif berlaku pada $billingDate (PPN) dan untuk kualifikasi pilihan user (PPh), lalu hitung. Tarif yang tidak ada = 422, tidak pernah diam-diam 0.
     * NON_PPN tidak memakai tarif PPN: snapshot 0.0000 dan id null.
     *
     * @param  list<string>  $percentages
     */
    public static function compute(Project $project, array $percentages, int $index, string $billingDate, string $qualification): array
    {
        $ppn = null;
        if ($project->contract_tax_mode !== 'NON_PPN') {
            $ppn = TaxRate::inForce('PPN', $billingDate)->first()
                ?? throw ValidationException::withMessages(['billing_date' => 'Tarif PPN yang berlaku pada tanggal penagihan belum ada. Tambahkan lebih dulu di menu Tarif Pajak.']);
        }
        $pph = TaxRate::inForce('PPH', $billingDate, $qualification)->first()
            ?? throw ValidationException::withMessages(['pph_qualification' => "Tarif PPh kualifikasi $qualification yang berlaku pada tanggal penagihan belum ada. Tambahkan lebih dulu di menu Tarif Pajak."]);

        $ppnRate = bcadd($ppn?->rate ?? '0', '0', 4);
        $pphRate = bcadd($pph->rate, '0', 4);

        return [
            'ppn_tax_rate_id' => $ppn?->id, 'pph_tax_rate_id' => $pph->id, 'ppn_rate_snapshot' => $ppnRate, 'pph_rate_snapshot' => $pphRate,
        ] + self::calculate($project->initial_contract_value, $project->contract_tax_mode, $percentages, $index, $ppnRate, $pphRate);
    }
}
