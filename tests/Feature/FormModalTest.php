<?php

namespace Tests\Feature;

use App\Models\Pks;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Formulir master data dibuka sebagai popup di daftar; setelah validasi gagal, popup yang benar terbuka lagi dengan isian lama.
class FormModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function pks(string $number): Pks
    {
        return (new Pks(['pks_number' => $number, 'name' => 'Nama '.$number, 'client_name' => 'Klien', 'issued_date' => '2026-01-05', 'status' => 'ACTIVE']))->forceFill(['created_by' => User::factory()->create()->id]);
    }

    public function test_list_renders_popups_and_keeps_the_page_fallback_link(): void
    {
        $this->pks('PKS/1')->save();
        $this->actingAs(User::factory()->role('STAFF_PIC', 'BM')->create())->get('/master/pks')
            ->assertOk()->assertSee('role="dialog"', false)->assertSee('/master/pks/create', false)->assertSee('formModal(false)', false);
    }

    public function test_failed_edit_reopens_only_that_record_with_its_old_input_and_errors(): void
    {
        $a = $this->pks('PKS/A');
        $a->save();
        $b = $this->pks('PKS/B');
        $b->save();
        $this->actingAs(User::factory()->role('STAFF_PIC', 'BM')->create());

        $response = $this->from('/master/pks')->put("/master/pks/{$b->id}", ['pks_number' => 'PKS/A', 'name' => 'Isian B diubah', 'client_name' => 'Klien', 'issued_date' => '2026-01-05', 'status' => 'ACTIVE', '_modal' => "pks-{$b->id}"]);
        $response->assertRedirect('/master/pks'); // assertSessionHasErrors memulai ulang sesi dan menua flash, jadi sengaja tidak dipakai

        $html = $this->get('/master/pks')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'formModal(true)'));
        $this->assertStringContainsString('Data belum bisa disimpan', $html);
        $this->assertSame(1, substr_count($html, 'Isian B diubah')); // popup baris A tetap berisi data aslinya
        $this->assertStringContainsString('Nama PKS/A', $html);
    }

    public function test_failed_create_reopens_the_create_popup(): void
    {
        $this->actingAs(User::factory()->role('STAFF_PIC', 'BM')->create());

        $this->from('/tax-rates')->post('/tax-rates', ['tax_type' => 'PPN', 'name' => 'Salah', 'rate' => 'abc', 'effective_from' => '2026-01-01', '_modal' => 'tax-rate-new']);
        $html = $this->get('/tax-rates')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'formModal(true)'));
        $this->assertStringContainsString('value="Salah"', $html);
        $this->assertSame(1, substr_count($html, 'Tarif maksimal dua angka desimal.')); // hanya di popup, bukan juga di banner halaman
    }

    public function test_users_who_cannot_create_get_no_popup_and_the_server_still_refuses(): void
    {
        $this->actingAs(User::factory()->role('MANAGER_GM', 'BM')->create());

        $this->get('/tax-rates')->assertOk()->assertDontSee('formModal(', false);
        $this->post('/tax-rates', ['tax_type' => 'PPN', 'name' => 'X', 'rate' => '11.00', 'effective_from' => '2026-01-01', '_modal' => 'tax-rate-new'])->assertForbidden();
    }
}
