<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** PRD 6.3: SPK Rp671.151.608,00 termasuk PPN 11%, termin 20%, PPh 2,65%. */
    public function test_prd_invoice_example_matches_to_the_cent(): void
    {
        $dppTotal = Money::removeTax('671151608.00', '11');
        $dpp = Money::percentOf($dppTotal, '20');
        $ppn = Money::percentOf($dpp, '11');
        $subtotal = Money::add($dpp, $ppn);
        $pph = Money::percentOf($dpp, '2.65');

        $this->assertSame('120928217.66', $dpp);
        $this->assertSame('13302103.94', $ppn);
        $this->assertSame('134230321.60', $subtotal);
        $this->assertSame('3204597.77', $pph);
        $this->assertSame('131025723.83', Money::sub($subtotal, $pph));
    }

    public function test_rounds_half_up_to_two_decimals(): void
    {
        $this->assertSame('1.01', Money::round('1.005'));
        $this->assertSame('1.00', Money::round('1.004999'));
        $this->assertSame('2.68', Money::round('2.675')); // float akan menghasilkan 2.67
        $this->assertSame('-1.01', Money::round('-1.005'));
        $this->assertSame('10.00', Money::round(10));
        $this->assertSame('0.30', Money::add('0.1', '0.2')); // float akan menghasilkan 0.30000000000000004
    }

    public function test_last_allocation_absorbs_the_rounding_difference(): void
    {
        $this->assertSame(['33.33', '33.33', '33.34'], Money::allocate('100.00', ['33.3333', '33.3333', '33.3334']));
        $this->assertSame(['0.03', '0.03', '0.04'], Money::allocate('0.10', ['33.3333', '33.3333', '33.3334']));

        // Jadwal DPP tiga termin dari contoh PRD: jumlahnya harus tepat sama dengan total.
        $parts = Money::allocate('604641088.29', ['20', '50', '30']);
        $this->assertSame(['120928217.66', '302320544.15', '181392326.48'], $parts);
        $this->assertSame('604641088.29', Money::add(...$parts));

        $this->assertSame(['95000000.00'], Money::allocate('95000000', ['100']));
    }

    public function test_allocation_needs_at_least_one_percentage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::allocate('100.00', []);
    }

    /** PRD FD-15 dan FD-14: margin base per mode pajak, lalu initial RAB. */
    public function test_margin_base_and_initial_rab(): void
    {
        $base = Money::addTax('180000000.00', '11'); // EXCLUSIVE_PPN
        $this->assertSame('199800000.00', $base);
        $margin = Money::percentOf($base, '12');
        $this->assertSame('23976000.00', $margin);
        $this->assertSame('175824000.00', Money::sub($base, $margin));

        $this->assertSame('100672741.20', Money::percentOf('671151608.00', '15')); // INCLUSIVE_PPN
        $this->assertSame('570478866.80', Money::sub('671151608.00', '100672741.20'));
    }

    public function test_ratio_pct_has_four_decimals_half_up(): void
    {
        $this->assertSame('33.3333', Money::ratioPct('1.00', '3.00'));
        $this->assertSame('66.6667', Money::ratioPct('2.00', '3.00'));
        $this->assertSame('0.0063', Money::ratioPct('1.00', '16000.00')); // 0,00625 tepat di tengah: naik
        $this->assertSame('100.0000', Money::ratioPct('570478866.80', '570478866.80'));
        $this->assertSame('0.0000', Money::ratioPct('0.00', '0.00')); // tanpa pembagian nol
    }

    public function test_keeps_precision_beyond_float_range(): void
    {
        $this->assertSame('9999999999999999.99', Money::add('9999999999999999.98', '0.01'));
        $this->assertSame('Rp9.999.999.999.999.999,99', Money::format('9999999999999999.99'));
    }

    public function test_formats_rupiah(): void
    {
        $this->assertSame('Rp671.151.608,00', Money::format('671151608.00'));
        $this->assertSame('Rp0,00', Money::format('0'));
        $this->assertSame('Rp999,50', Money::format('999.5'));
        $this->assertSame('-Rp1.500.000,00', Money::format('-1500000'));
        $this->assertSame('—', Money::format(null));
    }

    public function test_compares_without_float(): void
    {
        $this->assertSame(1, Money::compare('88000000.00', '80000000.00')); // nego di atas budget = overbudget
        $this->assertSame(0, Money::compare('10', '10.00'));
        $this->assertSame(-1, Money::compare('0.01', '0.02'));
    }
}
