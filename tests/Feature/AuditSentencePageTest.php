<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Halaman Audit Log menampilkan kalimat Indonesia; baris LOGIN lama disembunyikan; semua teks di-escape.
class AuditSentencePageTest extends TestCase
{
    use RefreshDatabase;

    private User $rina;

    private User $direksi;

    private Project $bm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->rina = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Rina']);
        $this->direksi = User::factory()->role('DIREKSI', null)->create(['name' => 'Direksi Satu']);
        $this->bm = Project::factory()->assignedTo($this->rina)->create(['project_code' => 'BM-2026-102']);
    }

    private function log(string $action, string $type, int $id, ?array $before = null, ?array $after = null, ?string $reason = null, ?User $by = null): void
    {
        DB::table('audit_logs')->insert(['user_id' => ($by ?? $this->rina)->id, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id, 'reason' => $reason,
            'data_before' => $before === null ? null : json_encode($before), 'data_after' => $after === null ? null : json_encode($after), 'created_at' => '2026-10-10 09:00:00']);
    }

    private function page(array $query = []): string
    {
        return $this->actingAs($this->direksi)->get('/audit-logs?'.http_build_query($query))->assertOk()->getContent();
    }

    /** Teks tanpa tag, spasi dirapikan, supaya kalimat bisa dicocokkan utuh. */
    private function text(string $html): string
    {
        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
    }

    public function test_page_renders_sentences_for_common_rows(): void
    {
        $pks = (int) $this->bm->pks_id;
        $pksNumber = DB::table('pks')->where('id', $pks)->value('pks_number');
        $rate = DB::table('tax_rates')->insertGetId(['tax_type' => 'PPN', 'name' => 'PPN 12%', 'rate' => '12.0000', 'effective_from' => '2027-01-01', 'created_by' => $this->rina->id]);
        $bq = DB::table('bq_items')->insertGetId(['project_id' => $this->bm->id, 'item_name' => 'Besi', 'created_by' => $this->rina->id]);
        $mutation = DB::table('bq_budget_mutations')->insertGetId(['project_id' => $this->bm->id, 'destination_bq_id' => $bq, 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'amount' => '25000000.00', 'reason' => 'x', 'submitted_by' => $this->rina->id]);
        $invoice = DB::table('invoices')->insertGetId(['project_id' => $this->bm->id, 'department' => 'BM', 'sequence_number' => 1, 'billing_type' => 'TERMIN', 'planned_amount' => '111.00', 'workflow_status' => 'PLANNED', 'created_by' => $this->rina->id]);
        $usman = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Usman Januar', 'is_active' => false]);

        $this->log('UPDATE', 'PKS', $pks, ['status' => 'DRAFT'], ['status' => 'ACTIVE']);
        $this->log('CREATE', 'TAX_RATE', $rate, null, ['name' => 'PPN 12%', 'rate' => '12.0000']);
        $this->log('SUBMIT', 'BQ_MUTATION', $mutation, null, ['amount' => '25000000.00', 'status' => 'SUBMITTED']);
        $this->log('APPROVE', 'INVOICE', $invoice, ['workflow_status' => 'INVOICE_SUBMITTED', 'version' => 1], ['workflow_status' => 'INVOICE_VERIFIED', 'version' => 2], null, $this->direksi);
        $this->log('LOCK', 'PROJECT', $this->bm->id, ['status' => 'COMPLETED'], ['status' => 'FINANCIALLY_CLOSED'], null, $this->direksi);
        $this->log('UPDATE', 'USER', $usman->id, ['is_active' => true], ['is_active' => false], null, $this->direksi);
        $this->log('REQUEST_REVISION', 'BQ_ITEM', $bq, null, null, 'Lampirkan nota', $this->direksi);

        $html = $this->page();
        $text = $this->text($html);
        $this->assertStringContainsString("Rina mengubah PKS $pksNumber 10/10/2026 16:00 WIB · Staff/PIC ↳ Status: Draf → Aktif", $text);
        $this->assertStringContainsString('Rina menambahkan tarif pajak PPN 12% (berlaku mulai 01/01/2027)', $text);
        $this->assertStringContainsString('Rina mengajukan relokasi budget di project BM-2026-102 sebesar Rp25.000.000,00', $text);
        $this->assertStringContainsString('Direksi Satu menyetujui invoice termin 1 project BM-2026-102 10/10/2026 16:00 WIB · Direksi ↳ Status: Invoice Diajukan → Invoice Terverifikasi', $text);
        $this->assertStringContainsString('Direksi Satu menutup buku project BM-2026-102 10/10/2026 16:00 WIB · Direksi ↳ Status: Selesai → Tutup Buku', $text);
        $this->assertStringContainsString('Direksi Satu menonaktifkan pengguna Usman Januar', $text);
        $this->assertStringNotContainsString('Aktif: Ya → Tidak', $text); // sudah terwakili kata kerja
        $this->assertStringContainsString('Direksi Satu meminta revisi item BQ Besi di project BM-2026-102 10/10/2026 16:00 WIB · Direksi Alasan: Lampirkan nota', $text);
        $this->assertStringContainsString('10/10/2026 16:00 WIB', $text); // 09:00 UTC
        $this->assertStringContainsString('href="'.url('/projects/'.$this->bm->id).'"', $html);
    }

    public function test_login_rows_are_hidden_and_not_a_filter_option(): void
    {
        $this->log('LOGIN', 'USER', $this->rina->id, null, null, 'LOGIN-ROW');
        $this->log('UPDATE', 'PROJECT', $this->bm->id, null, null, 'ROW-LAIN');

        $html = $this->page();
        $this->assertStringContainsString('ROW-LAIN', $html);
        $this->assertStringNotContainsString('LOGIN-ROW', $html);
        $this->assertStringNotContainsString('masuk ke', $html);
        $this->assertStringNotContainsString('value="LOGIN"', $html);
    }

    public function test_filter_options_are_indonesian_but_submit_enum_values(): void
    {
        $html = $this->page();
        foreach (['CREATE' => 'Menambahkan', 'UPDATE' => 'Mengubah', 'SUBMIT' => 'Mengajukan', 'APPROVE' => 'Menyetujui', 'REQUEST_REVISION' => 'Meminta revisi'] as $value => $label) {
            $this->assertMatchesRegularExpression('/<option value="'.$value.'"[^>]*>\s*'.$label.'\s*<\/option>/', $html);
        }
        foreach (['Kontak Bouwheer', 'Relokasi Budget', 'Pengguna', 'Tarif Pajak', 'Item BQ'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringNotContainsString('BQ_MUTATION</option>', $html);
    }

    public function test_user_text_is_escaped(): void
    {
        $bq = DB::table('bq_items')->insertGetId(['project_id' => $this->bm->id, 'item_name' => '<script>alert(1)</script>', 'created_by' => $this->rina->id]);
        $this->log('UPDATE', 'BQ_ITEM', $bq, ['item_name' => 'a'], ['item_name' => '<img src=x onerror=alert(2)>'], '<b onmouseover=alert(3)>alasan</b>');

        $html = $this->page();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b onmouseover', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
        $this->assertStringContainsString('&lt;b onmouseover=alert(3)&gt;alasan&lt;/b&gt;', $html);
    }

    public function test_missing_rows_fall_back_to_snapshot_then_id_and_page_uses_constant_lookup_queries(): void
    {
        $this->log('CREATE', 'PKS', 9001, null, ['pks_number' => 'PKS/HAPUS/1']);
        $this->log('UPDATE', 'PKS', 9002);
        $this->assertStringContainsString('menambahkan PKS PKS/HAPUS/1', $this->text($this->page()));
        $this->assertStringContainsString('mengubah PKS #9002', $this->text($this->page()));

        foreach (range(1, 20) as $i) {
            $this->log('UPDATE', 'PKS', $this->bm->pks_id);
            $this->log('UPDATE', 'PROJECT', $this->bm->id);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page();
        $queries = collect(DB::getQueryLog())->pluck('query');
        $this->assertSame(1, $queries->filter(fn ($q) => str_contains($q, 'from `pks`'))->count()); // satu query per tabel untuk seluruh halaman, bukan per baris
        $this->assertLessThanOrEqual(2, $queries->filter(fn ($q) => str_contains($q, 'from `projects` where `id`'))->count());
    }

    public function test_manager_scope_is_unchanged_and_links_only_own_projects(): void
    {
        $manager = User::factory()->role('MANAGER_GM', 'BM')->create(['name' => 'Manajer Budi']);
        $cp = Project::factory()->create(['department' => 'CP', 'project_code' => 'CP-RAHASIA']);
        $this->log('UPDATE', 'PROJECT', $this->bm->id, ['status' => 'ACTIVE'], ['status' => 'COMPLETED']);
        $this->log('UPDATE', 'PROJECT', $cp->id, ['status' => 'ACTIVE'], ['status' => 'COMPLETED']);
        $this->log('UPDATE', 'PKS', $this->bm->pks_id, ['status' => 'DRAFT'], ['status' => 'ACTIVE']);
        $this->log('UPDATE', 'USER', $this->rina->id, ['is_active' => true], ['is_active' => false]);

        $res = $this->actingAs($manager)->get('/audit-logs')->assertOk();
        $text = $this->text($res->getContent());
        $this->assertStringContainsString('Rina menandai selesai project BM-2026-102', $text);
        $this->assertStringNotContainsString('CP-RAHASIA', $text);
        $this->assertStringNotContainsString('menonaktifkan pengguna', $text);
        $this->assertStringNotContainsString('mengubah PKS', $text);
    }
}
