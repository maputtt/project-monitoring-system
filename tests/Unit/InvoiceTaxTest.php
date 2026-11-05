<?php

namespace Tests\Unit;

use App\Support\InvoiceTax;
use PHPUnit\Framework\TestCase;

// Pajak invoice (PRD 5.5, 6.3) murni aritmetika; tarif dan database diuji di Feature\InvoiceSubmitTest.
class InvoiceTaxTest extends TestCase
{
    public function test_prd_example_matches_the_api_table_to_the_cent(): void
    {
        // SPK Rp671.151.608,00 termasuk PPN 11%, termin 50% (dari 50/50), PPh kualifikasi B 2,65%.
        $this->assertSame([
            'dpp_amount' => '302320544.15', 'ppn_amount' => '33255259.86', 'subtotal_amount' => '335575804.01',
            'pph_amount' => '8011494.42', 'net_received_amount' => '327564309.59',
        ], InvoiceTax::calculate('671151608.00', 'INCLUSIVE_PPN', ['50.0000', '50.0000'], 0, '11.0000', '2.6500'));

        // Pratinjau API.md: kualifikasi A 1,75% pada termin yang sama.
        $a = InvoiceTax::calculate('671151608.00', 'INCLUSIVE_PPN', ['50.0000', '50.0000'], 0, '11.0000', '1.7500');
        $this->assertSame(['5290609.52', '330285194.49'], [$a['pph_amount'], $a['net_received_amount']]);
    }

    public function test_termin_dpp_shares_sum_exactly_to_the_total_dpp_and_the_last_absorbs_rounding(): void
    {
        $dpp = fn (int $i) => InvoiceTax::calculate('671151608.00', 'INCLUSIVE_PPN', ['50.0000', '50.0000'], $i, '11.0000', '2.6500')['dpp_amount'];

        $this->assertSame(['302320544.15', '302320544.14'], [$dpp(0), $dpp(1)]); // total DPP 604641088.29
        $this->assertSame('604641088.29', bcadd($dpp(0), $dpp(1), 2));
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
