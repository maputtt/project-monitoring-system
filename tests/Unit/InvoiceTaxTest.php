<?php

namespace Tests\Unit;

use App\Support\InvoiceTax;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

// Pajak invoice (PRD 5.5, 6.3) murni aritmetika; tarif dan database diuji di Feature\InvoiceSubmitTest.
class InvoiceTaxTest extends TestCase
{
    public function test_prd_example_first_termin_follows_the_gross_share_not_the_dpp_share(): void
    {
        // SPK Rp671.151.608,00 termasuk PPN 11%, termin 50% (dari 50/50), PPh kualifikasi B 2,65%. Subtotal = bagian bruto persis.
        $this->assertSame([
            'dpp_amount' => '302320544.14', 'ppn_amount' => '33255259.86', 'subtotal_amount' => '335575804.00',
            'pph_amount' => '8011494.42', 'net_received_amount' => '327564309.58',
        ], InvoiceTax::calculate('671151608.00', 'INCLUSIVE_PPN', ['50.0000', '50.0000'], 0, '11.0000', '2.6500'));

        // Pratinjau API.md: kualifikasi A 1,75% pada termin yang sama.
        $a = InvoiceTax::calculate('671151608.00', 'INCLUSIVE_PPN', ['50.0000', '50.0000'], 0, '11.0000', '1.7500');
        $this->assertSame(['5290609.52', '330285194.48'], [$a['pph_amount'], $a['net_received_amount']]);
    }

    public function test_last_termin_absorbs_the_cumulative_dpp_ppn_and_subtotal_difference(): void
    {
        $calc = fn (string $v, string $mode, array $pcts, int $i) => InvoiceTax::calculate($v, $mode, $pcts, $i, '11.0000', '2.6500');

        // 50/50: terakhir menyerap DPP +0,01 dan PPN -0,01; total DPP 604641088.29, total PPN 66510519.71.
        $this->assertSame(['302320544.15', '33255259.85', '335575804.00'], array_values(array_intersect_key($calc('671151608.00', 'INCLUSIVE_PPN', ['50', '50'], 1), array_flip(['dpp_amount', 'ppn_amount', 'subtotal_amount']))));

        // Regresi 20/30/45/5: seluruh selisih jatuh ke termin 5%.
        $last = $calc('671151608.00', 'INCLUSIVE_PPN', ['20', '30', '45', '5'], 3);
        $this->assertSame(['30232054.41', '3325525.99', '33557580.40'], [$last['dpp_amount'], $last['ppn_amount'], $last['subtotal_amount']]);

        // 50.000.000 95/5 dan 100%: sebelumnya jumlah subtotal 50.000.000,01.
        $this->assertSame(['42792792.79', '4707207.21', '47500000.00'], array_values(array_intersect_key($calc('50000000.00', 'INCLUSIVE_PPN', ['95', '5'], 0), array_flip(['dpp_amount', 'ppn_amount', 'subtotal_amount']))));
        $this->assertSame(['2252252.26', '247747.74', '2500000.00'], array_values(array_intersect_key($calc('50000000.00', 'INCLUSIVE_PPN', ['95', '5'], 1), array_flip(['dpp_amount', 'ppn_amount', 'subtotal_amount']))));
        $this->assertSame(['45045045.05', '4954954.95', '50000000.00'], array_values(array_intersect_key($calc('50000000.00', 'INCLUSIVE_PPN', ['100'], 0), array_flip(['dpp_amount', 'ppn_amount', 'subtotal_amount']))));
    }

    public function test_subtotals_sum_exactly_to_the_contract_total_in_every_mode(): void
    {
        $cases = [
            ['671151608.00', ['50', '50']], ['671151608.00', ['20', '30', '45', '5']], ['50000000.00', ['95', '5']], ['50000000.00', ['100']],
            ['1000.01', ['50', '50']], ['1000000.05', ['50', '50']], ['999999.99', ['33.3333', '33.3333', '33.3334']],
        ];
        foreach (['INCLUSIVE_PPN', 'EXCLUSIVE_PPN', 'NON_PPN'] as $mode) {
            foreach ($cases as [$total, $pcts]) {
                $sub = $dpp = '0.00';
                foreach ($pcts as $i => $_) {
                    $r = InvoiceTax::calculate($total, $mode, $pcts, $i, '11.0000', '2.6500');
                    $sub = bcadd($sub, $r['subtotal_amount'], 2);
                    $dpp = bcadd($dpp, $r['dpp_amount'], 2);
                    $this->assertSame($r['subtotal_amount'], bcadd($r['dpp_amount'], $r['ppn_amount'], 2), "$mode $total termin $i");
                }
                $expected = $mode === 'EXCLUSIVE_PPN' ? bcadd($total, Money::percentOf($total, '11.0000'), 2) : $total;
                $this->assertSame($expected, $sub, "$mode $total ".implode('/', $pcts));
                if ($mode !== 'INCLUSIVE_PPN') {
                    $this->assertSame($total, $dpp, "$mode $total DPP");
                }
            }
        }
    }

    public function test_exclusive_ppn_uses_the_contract_value_as_dpp(): void
    {
        $this->assertSame([
            'dpp_amount' => '50000000.00', 'ppn_amount' => '5500000.00', 'subtotal_amount' => '55500000.00',
            'pph_amount' => '1325000.00', 'net_received_amount' => '54175000.00',
        ], InvoiceTax::calculate('100000000.00', 'EXCLUSIVE_PPN', ['50.0000', '50.0000'], 0, '11.0000', '2.6500'));

        // Pembulatan half-up: 1000,01 x 50% = 500,005 menjadi 500,01; termin terakhir 500,00.
        $this->assertSame([
            'dpp_amount' => '500.01', 'ppn_amount' => '55.00', 'subtotal_amount' => '555.01', 'pph_amount' => '13.25', 'net_received_amount' => '541.76',
        ], InvoiceTax::calculate('1000.01', 'EXCLUSIVE_PPN', ['50', '50'], 0, '11.0000', '2.6500'));
        $this->assertSame('500.00', InvoiceTax::calculate('1000.01', 'EXCLUSIVE_PPN', ['50', '50'], 1, '11.0000', '2.6500')['dpp_amount']);

        // 1.000.000,05 50/50: PPN total 110.000,01; termin 1 PPN 55.000,00, terakhir menyerap 55.000,01 dan subtotal total tetap 1.110.000,06.
        $t1 = InvoiceTax::calculate('1000000.05', 'EXCLUSIVE_PPN', ['50', '50'], 0, '11.0000', '2.6500');
        $t2 = InvoiceTax::calculate('1000000.05', 'EXCLUSIVE_PPN', ['50', '50'], 1, '11.0000', '2.6500');
        $this->assertSame(['500000.03', '55000.00', '555000.03'], [$t1['dpp_amount'], $t1['ppn_amount'], $t1['subtotal_amount']]);
        $this->assertSame(['500000.02', '55000.01', '555000.03'], [$t2['dpp_amount'], $t2['ppn_amount'], $t2['subtotal_amount']]);
    }

    public function test_non_ppn_has_no_ppn_whatever_rate_is_passed(): void
    {
        $this->assertSame([
            'dpp_amount' => '300000.00', 'ppn_amount' => '0.00', 'subtotal_amount' => '300000.00',
            'pph_amount' => '5250.00', 'net_received_amount' => '294750.00',
        ], InvoiceTax::calculate('1000000.00', 'NON_PPN', ['30.0000', '70.0000'], 0, '11.0000', '1.7500'));

        $this->assertSame('687750.00', InvoiceTax::calculate('1000000.00', 'NON_PPN', ['30.0000', '70.0000'], 1, '0.0000', '1.7500')['net_received_amount']);
    }
}
