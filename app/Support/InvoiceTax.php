<?php

namespace App\Support;

use App\Models\Project;
use App\Models\TaxRate;
use Illuminate\Validation\ValidationException;

/**
 * Pajak satu invoice (PRD 5.5 dan 6.3), dipakai bersama oleh submit dan pratinjau supaya angkanya pasti sama.
 *
 * Persentase termin adalah persentase nilai SPK awal (initial_contract_value), jadi termin PLANNED tidak ikut menyusut setelah koreksi nilai kontrak.
 * DPP total kontrak: INCLUSIVE_PPN = nilai kontrak / (1 + PPN), EXCLUSIVE_PPN dan NON_PPN = nilai kontrak apa adanya.
 * DPP invoice = bagian termin ini dari DPP total lewat Money::allocate (jumlah seluruh termin tepat sama dengan DPP total, termin terakhir menyerap selisih).
 * PPN = DPP x tarif PPN (NON_PPN: 0), subtotal = DPP + PPN, PPh = DPP x tarif PPh, diterima bersih = subtotal - PPh.
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
        $dppTotal = $taxMode === 'INCLUSIVE_PPN' ? Money::removeTax($contractValue, $ppnRate) : Money::round($contractValue);
        $dpp = Money::allocate($dppTotal, $percentages)[$index];
        $ppn = $taxMode === 'NON_PPN' ? '0.00' : Money::percentOf($dpp, $ppnRate);
        $subtotal = Money::add($dpp, $ppn);
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
