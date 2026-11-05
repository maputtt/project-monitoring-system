<?php

namespace Tests\Feature;

use App\Models\TaxRate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// PRD 6.1, FD-18, AC-25, AC-26: master tarif pajak, hanya versi baru, tanpa ubah atau hapus.
class TaxRateTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
    }

    private function form(array $overrides = []): array
    {
        return $overrides + ['tax_type' => 'PPN', 'name' => 'PPN 11%', 'rate' => '11.00', 'effective_from' => '2026-01-01'];
    }

    private function pphForm(array $overrides = []): array
    {
        return $overrides + ['tax_type' => 'PPH', 'vendor_qualification' => 'A', 'name' => 'PPh A', 'rate' => '1.75', 'effective_from' => '2026-01-01'];
    }

    private function audit(): Collection
    {
        return DB::table('audit_logs')->where('entity_type', 'TAX_RATE')->orderBy('id')->get();
    }

    public function test_access_per_role_and_guest(): void
    {
        $this->get('/tax-rates')->assertRedirect('/login');
        $this->get('/tax-rates/create')->assertRedirect('/login');
        $this->post('/tax-rates', $this->form())->assertRedirect('/login');

        $manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->actingAs($manager)->get('/tax-rates')->assertOk()->assertDontSee('Tambah versi tarif');
        $this->get('/tax-rates/create')->assertForbidden();
        $this->post('/tax-rates', $this->form())->assertForbidden();

        foreach ([['DIREKSI', null], ['SYSTEM_ADMIN', 'ITBS']] as [$role, $department]) {
            $this->actingAs(User::factory()->role($role, $department)->create());
            $this->get('/tax-rates')->assertForbidden();
            $this->get('/tax-rates/create')->assertForbidden();
            $this->post('/tax-rates', $this->form())->assertForbidden();
        }

        $this->actingAs($this->staff)->get('/tax-rates')->assertOk()->assertSee('Tambah versi tarif');
        $this->get('/tax-rates/create')->assertOk();
        $this->assertSame(0, TaxRate::count());
    }

    public function test_effective_from_is_at_most_one_year_ahead_in_jakarta(): void
    {
        // 03:00 WIB tanggal 14 adalah 20:00 UTC tanggal 13: batas harus mengikuti tanggal Jakarta.
        $this->travelTo(Carbon::parse('2026-10-14 03:00', 'Asia/Jakarta'));

        $this->actingAs($this->staff)->post('/tax-rates', $this->form(['effective_from' => '2027-10-15']))
            ->assertSessionHasErrors(['effective_from' => 'Tanggal mulai berlaku paling lambat 14/10/2027 (satu tahun dari hari ini).']);
        $this->assertSame(0, TaxRate::count());
        $this->assertCount(0, $this->audit());

        $this->post('/tax-rates', $this->form(['effective_from' => '2027-10-14']))->assertSessionHasNoErrors();
        $this->assertSame(1, TaxRate::count());
    }

    public function test_staff_of_any_division_can_add(): void
    {
        $this->actingAs($this->staff)->post('/tax-rates', $this->form())->assertRedirect('/tax-rates');
        $cp = User::factory()->role('STAFF_PIC', 'CP')->create();
        $this->actingAs($cp)->post('/tax-rates', $this->form(['effective_from' => '2026-06-01', 'rate' => '12.00']))->assertRedirect('/tax-rates');

        $this->assertSame([$this->staff->id, $cp->id], TaxRate::orderBy('id')->pluck('created_by')->all());
    }

    public function test_validation_rules(): void
    {
        $invalid = [
            'PPh tanpa kualifikasi' => [$this->pphForm(['vendor_qualification' => '']), 'vendor_qualification'],
            'PPh kualifikasi di luar A/B' => [$this->pphForm(['vendor_qualification' => 'C']), 'vendor_qualification'],
            'tarif negatif' => [$this->form(['rate' => '-1.00']), 'rate'],
            'tiga desimal' => [$this->form(['rate' => '11.005']), 'rate'],
            'di atas 100' => [$this->form(['rate' => '100.01']), 'rate'],
            'jenis tidak dikenal' => [$this->form(['tax_type' => 'PPNBM']), 'tax_type'],
            'tanpa nama' => [$this->form(['name' => '']), 'name'],
            'huruf' => [$this->form(['rate' => 'abc']), 'rate'],
            'notasi ilmiah' => [$this->form(['rate' => '1e2']), 'rate'],
            'koma desimal' => [$this->form(['rate' => '11,5']), 'rate'],
            'array' => [$this->form(['rate' => ['1']]), 'rate'],
            'seperseribu' => [$this->form(['rate' => '0.001']), 'rate'],
            'kosong' => [$this->form(['rate' => '']), 'rate'],
        ];

        foreach ($invalid as $why => [$payload, $field]) {
            $this->actingAs($this->staff)->post('/tax-rates', $payload)->assertSessionHasErrors($field);
            $this->assertSame(0, TaxRate::count(), $why);
        }
        $this->assertCount(0, $this->audit());

        $this->post('/tax-rates', $this->form(['rate' => '100']))->assertSessionHasNoErrors();
        $this->assertSame('100.00', TaxRate::sole()->rate);
    }

    public function test_rates_are_stored_exactly_and_extra_fields_are_ignored(): void
    {
        foreach (['2.65' => '2.65', '0.29' => '0.29', '99.99' => '99.99', '11.1' => '11.10'] as $input => $stored) {
            $n = ($n ?? 0) + 1;
            $this->actingAs($this->staff)->post('/tax-rates', $this->pphForm(['rate' => $input, 'effective_from' => "2026-0{$n}-01"]) + [
                'id' => 999, 'created_by' => 999, 'is_active' => '0', 'effective_until' => '2026-12-31', 'created_at' => '2000-01-01',
            ])->assertSessionHasNoErrors();

            $row = TaxRate::latest('id')->first();
            $this->assertSame($stored, $row->getRawOriginal('rate'));
            $this->assertSame($stored, json_decode($this->audit()->last()->data_after, true)['rate']);
            $this->assertNotSame(999, $row->id);
            $this->assertSame([$this->staff->id, true, null], [$row->created_by, $row->is_active, $row->effective_until]);
            $this->assertNotSame('2000-01-01', $row->created_at->toDateString());
        }
    }

    public function test_failure_while_inserting_rolls_back_the_closing_of_the_old_version(): void
    {
        $this->actingAs($this->staff)->post('/tax-rates', $this->form());
        $auditBefore = $this->audit()->count();

        // Memaksa gagal tepat saat insert, setelah versi lama ditutup dan auditnya ditulis.
        TaxRate::creating(fn () => throw new \RuntimeException('insert gagal'));
        $this->withoutExceptionHandling();
        try {
            $this->post('/tax-rates', $this->form(['rate' => '12.00', 'effective_from' => '2026-03-01']));
            $this->fail('Insert seharusnya gagal.');
        } catch (\RuntimeException) {
        }

        $this->assertNull(TaxRate::sole()->effective_until);
        $this->assertCount($auditBefore, $this->audit());
    }

    public function test_ppn_forces_qualification_to_null(): void
    {
        $this->actingAs($this->staff)->post('/tax-rates', $this->form(['vendor_qualification' => 'A']))->assertSessionHasNoErrors();
        $this->assertNull(TaxRate::sole()->vendor_qualification);

        $this->post('/tax-rates', $this->form(['vendor_qualification' => 'Z', 'effective_from' => '2026-02-01']))->assertSessionHasNoErrors(); // nilai tak valid ikut dibuang, bukan ditolak
        $this->assertSame(2, TaxRate::whereNull('vendor_qualification')->count());
    }

    public function test_new_version_closes_previous_one_the_day_before_and_is_audited(): void
    {
        $this->actingAs($this->staff)->post('/tax-rates', $this->form());
        $old = TaxRate::sole();
        $this->assertCount(1, $this->audit()); // versi pertama: tidak ada yang ditutup

        $this->post('/tax-rates', $this->form(['name' => 'PPN 12%', 'rate' => '12.00', 'effective_from' => '2026-03-01']))->assertRedirect('/tax-rates');

        $this->assertSame('2026-02-28', $old->fresh()->effective_until->toDateString());
        $new = TaxRate::where('rate', '12.00')->sole();
        $this->assertNull($new->effective_until);
        $this->assertSame('12.00', $new->getRawOriginal('rate'));

        $logs = $this->audit();
        $this->assertCount(3, $logs);
        $this->assertSame(['UPDATE', $old->id, $this->staff->id], [$logs[1]->action, $logs[1]->entity_id, $logs[1]->user_id]);
        $this->assertSame(['effective_until' => null], json_decode($logs[1]->data_before, true));
        $this->assertSame(['effective_until' => '2026-02-28'], json_decode($logs[1]->data_after, true));
        $this->assertSame(['CREATE', $new->id, $this->staff->id], [$logs[2]->action, $logs[2]->entity_id, $logs[2]->user_id]);
        $this->assertSame('12.00', json_decode($logs[2]->data_after, true)['rate']);
    }

    public function test_first_version_closes_nothing(): void
    {
        $this->actingAs($this->staff)->post('/tax-rates', $this->form());

        $this->assertNull(TaxRate::sole()->effective_until);
        $this->assertSame(['CREATE'], $this->audit()->pluck('action')->all());
    }

    public function test_new_version_must_start_after_the_latest_version(): void
    {
        $this->actingAs($this->staff)->post('/tax-rates', $this->form(['effective_from' => '2026-03-01']));

        foreach (['2026-03-01', '2026-02-01'] as $date) {
            $this->post('/tax-rates', $this->form(['effective_from' => $date, 'rate' => '12.00']))->assertSessionHasErrors('effective_from');
        }

        $this->assertSame(1, TaxRate::count());
        $this->assertNull(TaxRate::sole()->effective_until);
        $this->assertCount(1, $this->audit());
    }

    public function test_pph_series_a_and_b_are_independent(): void
    {
        $this->actingAs($this->staff)->post('/tax-rates', $this->pphForm());
        $this->post('/tax-rates', $this->pphForm(['vendor_qualification' => 'B', 'name' => 'PPh B', 'rate' => '2.65', 'effective_from' => '2026-02-01']));
        $this->post('/tax-rates', $this->form());
        $this->assertSame(3, TaxRate::whereNull('effective_until')->count());

        $this->post('/tax-rates', $this->pphForm(['rate' => '2.00', 'effective_from' => '2026-04-01']));

        $this->assertSame('2026-03-31', TaxRate::where('rate', '1.75')->sole()->effective_until->toDateString());
        $this->assertNull(TaxRate::where('rate', '2.65')->sole()->effective_until);
        $this->assertNull(TaxRate::where('tax_type', 'PPN')->sole()->effective_until);
    }

    public function test_there_is_no_route_to_change_or_delete_a_rate(): void
    {
        $this->actingAs($this->staff)->post('/tax-rates', $this->form());

        foreach (['put', 'patch', 'delete'] as $method) {
            $this->{$method}('/tax-rates', $this->form(['rate' => '99.00']))->assertStatus(405);
            $this->{$method}('/tax-rates/'.TaxRate::sole()->id)->assertNotFound();
        }

        $allowed = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'tax-rates'))->flatMap->methods()->unique()->sort()->values()->all();
        $this->assertSame(['GET', 'HEAD', 'POST'], $allowed);
        $this->assertSame('11.00', TaxRate::sole()->rate);
    }

    public function test_in_force_scope_at_the_boundaries(): void
    {
        $this->actingAs($this->staff)->post('/tax-rates', $this->form());
        $this->post('/tax-rates', $this->form(['rate' => '12.00', 'effective_from' => '2026-03-01']));
        $this->post('/tax-rates', $this->pphForm());
        $rate = fn (string $type, string $date, ?string $qualification = null) => TaxRate::inForce($type, $date, $qualification)->get()->pluck('rate')->all();

        $this->assertSame(['11.00'], $rate('PPN', '2026-02-28')); // hari terakhir versi lama
        $this->assertSame(['12.00'], $rate('PPN', '2026-03-01')); // hari pertama versi baru
        $this->assertSame(['11.00'], $rate('PPN', '2026-01-01'));
        $this->assertSame([], $rate('PPN', '2025-12-31')); // sebelum versi mana pun
        $this->assertSame(['12.00'], $rate('PPN', '2030-01-01')); // versi terbuka
        $this->assertSame([], $rate('PPH', '2026-06-01')); // PPh butuh kualifikasi
        $this->assertSame([], $rate('PPH', '2026-06-01', 'B'));
        $this->assertSame(['1.75'], $rate('PPH', '2026-06-01', 'A'));

        TaxRate::where('rate', '12.00')->update(['is_active' => false]);
        $this->assertSame([], $rate('PPN', '2026-03-01')); // baris nonaktif diabaikan
    }

    public function test_list_has_no_used_column(): void
    {
        $this->actingAs($this->staff)->post('/tax-rates', $this->form());
        $this->get('/tax-rates')->assertOk()->assertSee('Berlaku sampai')->assertDontSee('Sudah dipakai');
    }
}
