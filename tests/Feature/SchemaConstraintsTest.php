<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Membuktikan invariant PRD yang dijaga langsung oleh database (MySQL).
class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private int $staff;

    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->role('STAFF_PIC')->create()->id;
        $this->manager = User::factory()->role('MANAGER_GM')->create()->id;
    }

    private function rejected(callable $insert, string $why): void
    {
        try {
            $insert();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("Database seharusnya menolak: {$why}");
    }

    private function project(array $o = []): int
    {
        static $n = 0;
        $n++;
        $pks = DB::table('pks')->insertGetId(['pks_number' => "PKS/{$n}", 'name' => 'PKS', 'client_name' => 'Klien', 'issued_date' => '2026-01-01', 'created_by' => $this->staff]);
        $contact = DB::table('bouwheer_contacts')->insertGetId(['name' => 'PIC', 'company_name' => 'Klien']);

        return DB::table('projects')->insertGetId($o + [
            'pks_id' => $pks, 'bouwheer_contact_id' => $contact, 'department' => 'BM', 'project_code' => "BM-{$n}", 'project_name' => 'Project', 'location' => 'Jakarta',
            'spk_number' => "SPK/{$n}", 'spk_date' => '2026-02-10', 'contract_value' => '671151608.00', 'initial_contract_value' => '671151608.00', 'contract_tax_mode' => 'INCLUSIVE_PPN',
            'margin_base_amount' => '671151608.00', 'target_margin_pct' => '15.0000', 'target_margin_amount' => '100672741.20',
            'initial_rab' => '570478866.80', 'effective_rab' => '570478866.80', 'payment_type' => 'TERMIN', 'payment_count' => 3,
            'start_date' => '2026-03-01', 'end_date' => '2026-11-30', 'created_by' => $this->staff,
        ]);
    }

    private function bq(int $project, array $o = []): int
    {
        return DB::table('bq_items')->insertGetId($o + ['project_id' => $project, 'item_name' => 'Item', 'created_by' => $this->staff]);
    }

    private function invoice(int $project, array $o = []): int
    {
        static $seq = 0;

        return DB::table('invoices')->insertGetId($o + [
            'project_id' => $project, 'department' => 'BM', 'sequence_number' => ++$seq, 'billing_type' => 'TERMIN', 'planned_amount' => '1000.00', 'created_by' => $this->staff,
        ]);
    }

    private function submitted(array $o = []): array
    {
        return $o + ['workflow_status' => 'INVOICE_SUBMITTED', 'billing_date' => '2026-04-15', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500', 'submitted_by' => $this->staff];
    }

    public function test_decimal_columns_keep_prd_tax_example_to_the_cent(): void
    {
        $id = $this->invoice($this->project(), $this->submitted(['invoice_number' => 'INV/1', 'dpp_amount' => '120928217.66', 'ppn_amount' => '13302103.94', 'subtotal_amount' => '134230321.60', 'pph_amount' => '3204597.77', 'net_received_amount' => '131025723.83']));
        $row = DB::table('invoices')->find($id);
        $this->assertSame(['120928217.66', '13302103.94', '134230321.60', '3204597.77', '131025723.83'], [$row->dpp_amount, $row->ppn_amount, $row->subtotal_amount, $row->pph_amount, $row->net_received_amount]);
    }

    public function test_initial_contract_value_is_required_and_not_negative(): void
    {
        $this->rejected(fn () => $this->project(['initial_contract_value' => null]), 'initial_contract_value NULL');
        $this->rejected(fn () => $this->project(['initial_contract_value' => '-0.01']), 'initial_contract_value negatif');
    }

    public function test_exactly_one_active_operational_bq_per_project(): void
    {
        $p = $this->project();
        $first = $this->bq($p, ['item_type' => 'OPERATIONAL']);
        $this->bq($p); // item NORMAL tidak dibatasi
        $this->bq($p);
        $this->rejected(fn () => $this->bq($p, ['item_type' => 'OPERATIONAL']), 'BQ OPERATIONAL kedua pada project yang sama');

        DB::table('bq_items')->where('id', $first)->update(['status' => 'ARCHIVED']);
        $this->bq($p, ['item_type' => 'OPERATIONAL']); // yang lama sudah diarsipkan
        $this->bq($this->project(), ['item_type' => 'OPERATIONAL']); // project lain
        $this->assertSame(2, DB::table('bq_items')->where('project_id', $p)->where('item_type', 'OPERATIONAL')->count());
    }

    public function test_money_and_status_values_are_guarded(): void
    {
        $p = $this->project();
        $bq = $this->bq($p, ['effective_budget' => '100.00']);
        $this->rejected(fn () => DB::table('bq_items')->where('id', $bq)->update(['effective_budget' => '-0.01']), 'saldo BQ negatif');
        $this->rejected(fn () => $this->bq($p, ['actual_cost' => '-1']), 'realisasi biaya negatif');
        $this->rejected(fn () => $this->bq($p, ['status' => 'REJECTED']), 'status BQ di luar daftar');
        $this->rejected(fn () => $this->project(['contract_value' => '-1']), 'nilai kontrak negatif');
        $this->rejected(fn () => $this->project(['payment_count' => 0]), 'payment_count nol');
        $this->rejected(fn () => $this->project(['end_date' => '2026-01-01']), 'tanggal selesai sebelum mulai');
        $this->rejected(fn () => $this->project(['department' => 'HR']), 'divisi di luar BM/CP/ITBS');
        $this->assertSame('100.00', DB::table('bq_items')->find($bq)->effective_budget);
    }

    public function test_exclusive_ppn_project_requires_rate_snapshot_and_others_forbid_it(): void
    {
        $this->rejected(fn () => $this->project(['contract_tax_mode' => 'EXCLUSIVE_PPN']), 'EXCLUSIVE_PPN tanpa snapshot tarif');
        $this->rejected(fn () => $this->project(['margin_base_ppn_rate_snapshot' => '11.0000']), 'INCLUSIVE_PPN dengan snapshot tarif');
        $id = $this->project(['contract_tax_mode' => 'EXCLUSIVE_PPN', 'margin_base_ppn_rate_snapshot' => '11.0000']);
        $this->assertSame('11.0000', DB::table('projects')->find($id)->margin_base_ppn_rate_snapshot);
    }

    public function test_budget_mutation_rules(): void
    {
        $p = $this->project();
        [$a, $b] = [$this->bq($p), $this->bq($p)];
        $base = ['project_id' => $p, 'destination_bq_id' => $b, 'mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'amount' => '10.00', 'reason' => 'x', 'submitted_by' => $this->staff];
        $ok = DB::table('bq_budget_mutations')->insertGetId($base);
        $this->rejected(fn () => DB::table('bq_budget_mutations')->insert(['amount' => '0'] + $base), 'nominal mutasi nol');
        $this->rejected(fn () => DB::table('bq_budget_mutations')->insert(['source_bq_id' => null] + $base), 'transfer antar BQ tanpa sumber');
        $this->rejected(fn () => DB::table('bq_budget_mutations')->insert(['source_bq_id' => $b] + $base), 'sumber sama dengan tujuan');
        $this->rejected(fn () => DB::table('bq_budget_mutations')->where('id', $ok)->update(['approved_by' => $this->staff]), 'pengaju menyetujui mutasinya sendiri');
        DB::table('bq_budget_mutations')->where('id', $ok)->update(['approved_by' => $this->manager, 'status' => 'APPROVED']);
        $this->assertSame('APPROVED', DB::table('bq_budget_mutations')->find($ok)->status);
    }

    public function test_return_mutation_has_no_destination_and_other_types_require_one(): void
    {
        $p = $this->project();
        [$a, $b] = [$this->bq($p), $this->bq($p)];
        $t = fn (array $o) => DB::table('bq_budget_mutations')->insert($o + ['project_id' => $p, 'amount' => '10.00', 'reason' => 'x', 'submitted_by' => $this->staff]);
        $return = ['mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $a, 'destination_bq_id' => null];
        $this->assertTrue($t($return));
        $this->rejected(fn () => $t(['destination_bq_id' => $b] + $return), 'RETURN dengan BQ tujuan');
        $this->rejected(fn () => $t(['source_bq_id' => null] + $return), 'RETURN tanpa sumber');
        $this->rejected(fn () => $t(['mutation_type' => 'TRANSFER_BETWEEN_BQ'] + $return), 'TRANSFER tanpa tujuan');
        $this->rejected(fn () => $t(['mutation_type' => 'FROM_UNALLOCATED_RAB', 'source_bq_id' => null] + $return), 'FROM_UNALLOCATED tanpa tujuan');
        $this->rejected(fn () => $t(['mutation_type' => 'BOGUS', 'destination_bq_id' => $b]), 'tipe mutasi tidak dikenal');
    }

    public function test_adjustment_type_allows_billing_percentage_only_from_the_list(): void
    {
        $p = $this->project();
        $row = fn (string $type) => DB::table('project_adjustments')->insert(['project_id' => $p, 'adjustment_type' => $type, 'old_data' => '{}', 'new_data' => '{}', 'reason' => 'x', 'submitted_by' => $this->staff]);
        $this->assertTrue($row('BILLING_PERCENTAGE'));
        $this->rejected(fn () => $row('BOGUS'), 'tipe penyesuaian tidak dikenal');
    }

    public function test_invoice_rules(): void
    {
        $p = $this->project();
        $this->rejected(fn () => $this->invoice($p, ['workflow_status' => 'REJECTED']), 'status invoice di luar daftar');
        $this->rejected(fn () => $this->invoice($p, $this->submitted()), 'invoice submitted tanpa nomor invoice');
        $this->rejected(fn () => $this->invoice($p, ['billing_percentage' => '100.0001']), 'persentase di atas 100');

        $id = $this->invoice($p, $this->submitted(['invoice_number' => 'INV/2026/001']));
        $this->assertSame(32, DB::selectOne("select character_maximum_length n from information_schema.columns where table_schema = database() and table_name = 'invoices' and column_name = 'workflow_status'")->n);
        DB::table('invoices')->where('id', $id)->update(['workflow_status' => 'INVOICE_REVISION_REQUIRED']); // 25 karakter muat

        $this->rejected(fn () => DB::table('invoices')->where('id', $id)->update(['workflow_status' => 'PAID']), 'PAID tanpa lock');
        $this->rejected(fn () => DB::table('invoices')->where('id', $id)->update(['approved_by' => $this->staff]), 'pengaju menyetujui invoicenya sendiri');
        DB::table('invoices')->where('id', $id)->update(['workflow_status' => 'PAID', 'approved_by' => $this->manager, 'locked_by' => $this->manager, 'locked_at' => now()]);
        $this->assertSame('PAID', DB::table('invoices')->find($id)->workflow_status);

        $seq = DB::table('invoices')->find($id)->sequence_number;
        $this->rejected(fn () => $this->invoice($p, ['sequence_number' => $seq]), 'urutan termin ganda dalam satu project');
    }

    public function test_invoice_number_is_unique_per_division_not_globally(): void
    {
        $bm = $this->project();
        $cp = $this->project(['department' => 'CP']);
        $this->invoice($bm, $this->submitted(['invoice_number' => 'INV/001']));
        $this->rejected(fn () => $this->invoice($bm, $this->submitted(['invoice_number' => 'INV/001'])), 'nomor invoice ganda dalam satu divisi');
        $this->invoice($cp, $this->submitted(['invoice_number' => 'INV/001', 'department' => 'CP'])); // divisi lain boleh memakai nomor yang sama
        $this->invoice($bm); // PLANNED tanpa nomor boleh lebih dari satu
        $this->invoice($bm);
        $this->assertSame(2, DB::table('invoices')->where('invoice_number', 'INV/001')->count());
    }

    public function test_tax_rate_qualification_only_applies_to_pph(): void
    {
        $rate = fn (array $o) => DB::table('tax_rates')->insert($o + ['name' => 'Tarif', 'rate' => '11.00', 'effective_from' => '2026-01-01', 'created_by' => $this->staff]);
        $this->rejected(fn () => $rate(['tax_type' => 'PPN', 'vendor_qualification' => 'A']), 'PPN dengan kualifikasi');
        $this->rejected(fn () => $rate(['tax_type' => 'PPH']), 'PPh tanpa kualifikasi');
        $this->rejected(fn () => $rate(['tax_type' => 'PPH', 'vendor_qualification' => 'C']), 'kualifikasi di luar A/B');
        $this->rejected(fn () => $rate(['tax_type' => 'PPN', 'effective_until' => '2025-12-31']), 'akhir berlaku sebelum mulai');
        $rate(['tax_type' => 'PPN']);
        $rate(['tax_type' => 'PPH', 'vendor_qualification' => 'A', 'rate' => '1.75']);
        $rate(['tax_type' => 'PPH', 'vendor_qualification' => 'B', 'rate' => '2.65']);
        $this->assertSame(3, DB::table('tax_rates')->count());
    }

    public function test_user_role_and_division_rules(): void
    {
        $this->rejected(fn () => User::factory()->role('STAFF_PIC', null)->create(), 'Staff/PIC tanpa divisi');
        $this->rejected(fn () => User::factory()->role('MANAGER_GM', null)->create(), 'Manager/GM tanpa divisi');
        $this->rejected(fn () => DB::table('users')->insert(['role' => 'FINANCE', 'name' => 'X', 'email' => 'x@example.com', 'password_hash' => 'x', 'department' => 'BM']), 'role di luar daftar');
        $this->assertNull(User::factory()->role('DIREKSI', null)->create()->department);
    }

    public function test_progress_and_document_rules(): void
    {
        $p = $this->project();
        $progress = fn (array $o) => DB::table('project_progress')->insert($o + ['project_id' => $p, 'entry_type' => 'WEEKLY_UPDATE', 'progress_date' => '2026-10-08', 'week_key' => '2026-W41', 'description' => 'x', 'created_by' => $this->staff]);
        $progress([]); // WEEKLY_UPDATE tanpa milestone
        $progress(['entry_type' => 'MILESTONE', 'milestone_name' => 'BAST terbit', 'milestone_status' => 'ACHIEVED', 'progress_percentage' => '100.00']);
        $this->rejected(fn () => $progress(['entry_type' => 'MILESTONE']), 'MILESTONE tanpa nama dan status');
        $this->rejected(fn () => $progress(['progress_percentage' => '100.01']), 'progress di atas 100');

        $doc = fn (array $o) => DB::table('documents')->insert($o + ['project_id' => $p, 'entity_type' => 'INVOICE', 'entity_id' => 1, 'document_type' => 'INVOICE_FILE', 'original_filename' => 'a.pdf', 'storage_key' => uniqid('k'), 'mime_type' => 'application/pdf', 'file_size' => 1024, 'uploaded_by' => $this->staff]);
        $doc(['file_size' => 52428800]); // tepat 50 MB
        $this->rejected(fn () => $doc(['file_size' => 52428801]), 'file di atas 50 MB');
        $this->rejected(fn () => $doc(['mime_type' => 'application/zip']), 'tipe file selain PDF/JPG/PNG');
        $this->rejected(fn () => $doc(['storage_key' => DB::table('documents')->value('storage_key')]), 'storage_key ganda');
        $this->assertSame(1, DB::table('documents')->count());
    }

    public function test_audit_log_is_append_only_and_financial_rows_cannot_be_deleted(): void
    {
        $p = $this->project();
        $this->bq($p);
        $log = DB::table('audit_logs')->insertGetId(['user_id' => $this->staff, 'action' => 'CREATE', 'entity_type' => 'PROJECT', 'entity_id' => $p]);
        $this->rejected(fn () => DB::table('audit_logs')->where('id', $log)->update(['reason' => 'diubah']), 'mengubah audit log');
        $this->rejected(fn () => DB::table('audit_logs')->where('id', $log)->delete(), 'menghapus audit log');
        $this->rejected(fn () => DB::table('audit_logs')->insert(['action' => 'REJECT', 'entity_type' => 'PROJECT', 'entity_id' => $p]), 'aksi REJECT sudah diganti REQUEST_REVISION');
        $this->rejected(fn () => DB::table('projects')->where('id', $p)->delete(), 'menghapus project yang punya BQ');
        $this->rejected(fn () => DB::table('users')->where('id', $this->staff)->delete(), 'menghapus user yang punya transaksi');
        $this->assertSame(1, DB::table('audit_logs')->count());
    }
}
