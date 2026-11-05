<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// INV-01 (PRD 5.5, 6.3; AC-12, AC-13, AC-24, AC-25): submit invoice dan pratinjau pajak. Angka contoh PRD harus tepat sampai sen.
class InvoiceSubmitTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Project $project;

    private int $inv1;

    private int $inv2;

    private int $ppn11;

    private int $pphA;

    private int $pphB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        // Kontrak PRD 6.3: Rp671.151.608,00 termasuk PPN, dua termin 50%.
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        [$this->inv1, $this->inv2] = [$this->invoice($this->project, 1), $this->invoice($this->project, 2)];
        $this->ppn11 = $this->rate('PPN', null, '11.00');
        $this->pphA = $this->rate('PPH', 'A', '1.75');
        $this->pphB = $this->rate('PPH', 'B', '2.65');
    }

    private function invoice(Project $project, int $seq, string $status = 'PLANNED'): int
    {
        return DB::table('invoices')->insertGetId([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000',
            'planned_amount' => '335575804.00', 'workflow_status' => $status, 'created_by' => $this->staff->id,
        ]);
    }

    private function rate(string $type, ?string $qualification, string $rate, string $from = '2025-01-01', ?string $until = null): int
    {
        return DB::table('tax_rates')->insertGetId([
            'tax_type' => $type, 'name' => "$type $rate", 'vendor_qualification' => $qualification, 'rate' => $rate,
            'effective_from' => $from, 'effective_until' => $until, 'created_by' => $this->staff->id,
        ]);
    }

    private function row(int $id): object
    {
        return DB::table('invoices')->where('id', $id)->first();
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['version' => 1, 'invoice_number' => 'INV/BM/2026/078', 'bapp_number' => 'BAPP/014', 'bast_number' => '', 'billing_date' => '2026-08-20', 'due_date' => '2026-12-31',
            'pph_qualification' => 'B', 'file' => UploadedFile::fake()->create('invoice-termin.pdf', 100, 'application/pdf')];
    }

    private function submit(int $id, array $extra = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->staff)->post("/invoices/$id/submit", $this->payload($extra));
    }

    private function audits(): int
    {
        return DB::table('audit_logs')->where('entity_type', 'INVOICE')->count();
    }

    private function docs(int $id): int
    {
        return DB::table('documents')->where('entity_type', 'INVOICE')->where('entity_id', $id)->count();
    }

    private function untouched(int $id, int $version = 1): void
    {
        $row = $this->row($id);
        $this->assertSame(['PLANNED', null, null, null, $version], [$row->workflow_status, $row->invoice_number, $row->dpp_amount, $row->ppn_rate_snapshot, (int) $row->version]);
        $this->assertSame([0, 0, []], [$this->audits(), $this->docs($id), Storage::disk('local')->allFiles()]);
    }

    public function test_prd_example_is_stored_to_the_cent_with_snapshots_file_and_audit(): void
    {
        $this->submit($this->inv1, ['pph_qualification' => null, 'pph_tax_rate_id' => $this->pphB])->assertRedirect("/projects/{$this->project->id}")->assertSessionHasNoErrors();

        $row = $this->row($this->inv1);
        $this->assertSame([
            'INVOICE_SUBMITTED', 'INV/BM/2026/078', 'BAPP/014', null, '2026-08-20', '11.0000', '2.6500',
            '302320544.15', '33255259.86', '335575804.01', '8011494.42', '327564309.59', 2, $this->staff->id,
        ], [
            $row->workflow_status, $row->invoice_number, $row->bapp_number, $row->bast_number, $row->billing_date, $row->ppn_rate_snapshot, $row->pph_rate_snapshot,
            $row->dpp_amount, $row->ppn_amount, $row->subtotal_amount, $row->pph_amount, $row->net_received_amount, (int) $row->version, (int) $row->submitted_by,
        ]);
        $this->assertSame([$this->ppn11, $this->pphB], [(int) $row->ppn_tax_rate_id, (int) $row->pph_tax_rate_id]);
        $this->assertNotNull($row->submitted_at);

        $doc = DB::table('documents')->where('entity_type', 'INVOICE')->where('entity_id', $this->inv1)->sole();
        $this->assertSame(['INVOICE_FILE', 'UPLOADED', $this->project->id, null], [$doc->document_type, $doc->status, (int) $doc->project_id, $doc->replaced_document_id]);
        Storage::disk('local')->assertExists($doc->storage_key);

        $audit = DB::table('audit_logs')->where('entity_type', 'INVOICE')->sole();
        [$before, $after] = [json_decode($audit->data_before), json_decode($audit->data_after)];
        $this->assertSame(['SUBMIT', $this->inv1, $this->staff->id, 'PLANNED', null], [$audit->action, (int) $audit->entity_id, (int) $audit->user_id, $before->workflow_status, $before->dpp_amount]);
        $this->assertSame(['INVOICE_SUBMITTED', '302320544.15', '327564309.59', (int) $doc->id], [$after->workflow_status, $after->dpp_amount, $after->net_received_amount, $after->invoice_document_id]);
    }

    public function test_preview_returns_the_api_example_and_stores_nothing(): void
    {
        $url = "/invoices/{$this->inv1}/tax-preview?billing_date=2026-08-20&pph_tax_rate_id={$this->pphA}";
        $this->actingAs($this->staff)->getJson($url)->assertOk()->assertExactJson([
            'ppn_rate' => '11.0000', 'pph_rate' => '1.7500', 'dpp_amount' => '302320544.15', 'ppn_amount' => '33255259.86',
            'subtotal_amount' => '335575804.01', 'pph_amount' => '5290609.52', 'net_received_amount' => '330285194.49',
        ]);
        $this->actingAs($this->staff)->getJson("/invoices/{$this->inv1}/tax-preview?billing_date=2026-08-20&pph_qualification=B")->assertOk()->assertJsonPath('net_received_amount', '327564309.59');
        $this->untouched($this->inv1);
    }

    public function test_preview_rejects_bad_input_and_missing_rates_with_422(): void
    {
        $get = fn (string $query) => $this->actingAs($this->staff)->getJson("/invoices/{$this->inv1}/tax-preview?$query");
        $get('billing_date=2026-08-20')->assertStatus(422)->assertJsonValidationErrors('pph_qualification');
        $get('pph_qualification=B')->assertStatus(422)->assertJsonValidationErrors('billing_date');
        $get('billing_date=2026-02-31&pph_qualification=B')->assertStatus(422)->assertJsonValidationErrors('billing_date');
        $get('billing_date=2026-08-20&pph_qualification=C')->assertStatus(422)->assertJsonValidationErrors('pph_qualification');
        $get("billing_date=2026-08-20&pph_tax_rate_id={$this->ppn11}")->assertStatus(422)->assertJsonValidationErrors('pph_tax_rate_id'); // bukan tarif PPh
        $get('billing_date=2024-12-31&pph_qualification=B')->assertStatus(422)->assertJsonValidationErrors('billing_date'); // sebelum PPN berlaku
        $this->untouched($this->inv1);
    }

    public function test_exclusive_ppn_takes_the_contract_value_as_dpp(): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['contract_tax_mode' => 'EXCLUSIVE_PPN', 'contract_value' => '100000000.00', 'initial_contract_value' => '100000000.00', 'margin_base_ppn_rate_snapshot' => '11.0000']);

        $this->submit($this->inv1)->assertSessionHasNoErrors();
        $row = $this->row($this->inv1);
        $this->assertSame(['50000000.00', '5500000.00', '55500000.00', '1325000.00', '54175000.00', '11.0000'],
            [$row->dpp_amount, $row->ppn_amount, $row->subtotal_amount, $row->pph_amount, $row->net_received_amount, $row->ppn_rate_snapshot]);
    }

    public function test_non_ppn_has_zero_ppn_stored_as_a_snapshot_and_needs_no_ppn_rate(): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['contract_tax_mode' => 'NON_PPN', 'contract_value' => '1000000.00', 'initial_contract_value' => '1000000.00']);
        DB::table('tax_rates')->where('id', $this->ppn11)->update(['is_active' => false]); // tidak ada tarif PPN sama sekali

        $this->submit($this->inv2)->assertSessionHasNoErrors();
        $row = $this->row($this->inv2);
        $this->assertSame(['500000.00', '0.00', '500000.00', '13250.00', '486750.00', '0.0000', null, 'INVOICE_SUBMITTED'],
            [$row->dpp_amount, $row->ppn_amount, $row->subtotal_amount, $row->pph_amount, $row->net_received_amount, $row->ppn_rate_snapshot, $row->ppn_tax_rate_id, $row->workflow_status]);
    }

    public function test_ppn_follows_the_billing_date_and_termin_dpp_sums_to_the_total(): void
    {
        DB::table('tax_rates')->where('id', $this->ppn11)->update(['effective_until' => '2026-08-31']);
        $ppn12 = $this->rate('PPN', null, '12.00', '2026-09-01');

        $this->submit($this->inv1, ['billing_date' => '2026-08-31'])->assertSessionHasNoErrors();
        $this->submit($this->inv2, ['billing_date' => '2026-09-01', 'invoice_number' => 'INV/BM/2026/079'])->assertSessionHasNoErrors();

        [$one, $two] = [$this->row($this->inv1), $this->row($this->inv2)];
        $this->assertSame(['11.0000', $this->ppn11, '12.0000', $ppn12], [$one->ppn_rate_snapshot, (int) $one->ppn_tax_rate_id, $two->ppn_rate_snapshot, (int) $two->ppn_tax_rate_id]);
        // 671151608 / 1,12 = 599242507,14 (DPP total pada PPN 12%); termin 2 menyerap pembulatan.
        $this->assertSame(['299621253.57', '35954550.43', '335575804.00', '7939963.22', '327635840.78'],
            [$two->dpp_amount, $two->ppn_amount, $two->subtotal_amount, $two->pph_amount, $two->net_received_amount]);
    }

    public function test_second_termin_absorbs_the_dpp_rounding_difference(): void
    {
        $this->submit($this->inv1)->assertSessionHasNoErrors();
        $this->submit($this->inv2, ['invoice_number' => 'INV/BM/2026/079'])->assertSessionHasNoErrors();

        $this->assertSame(['302320544.15', '302320544.14'], [$this->row($this->inv1)->dpp_amount, $this->row($this->inv2)->dpp_amount]);
        $this->assertSame('604641088.29', bcadd($this->row($this->inv1)->dpp_amount, $this->row($this->inv2)->dpp_amount, 2));
    }

    public function test_pph_follows_the_chosen_qualification_and_the_rate_in_force_on_the_billing_date(): void
    {
        DB::table('tax_rates')->where('id', $this->pphB)->update(['effective_until' => '2026-08-31']);
        $newB = $this->rate('PPH', 'B', '3.00', '2026-09-01');

        $this->submit($this->inv1, ['pph_qualification' => 'A'])->assertSessionHasNoErrors();
        $this->submit($this->inv2, ['pph_tax_rate_id' => $this->pphB, 'pph_qualification' => null, 'billing_date' => '2026-09-05', 'invoice_number' => 'INV/BM/2026/079'])->assertSessionHasNoErrors();

        [$one, $two] = [$this->row($this->inv1), $this->row($this->inv2)];
        $this->assertSame(['1.7500', $this->pphA, '5290609.52'], [$one->pph_rate_snapshot, (int) $one->pph_tax_rate_id, $one->pph_amount]);
        // Id lama (B 2,65%) menunjuk kualifikasi B; yang dipakai versi B yang berlaku pada 2026-09-05.
        $this->assertSame(['3.0000', $newB], [$two->pph_rate_snapshot, (int) $two->pph_tax_rate_id]);
    }

    public function test_missing_rate_on_the_billing_date_is_422_without_changes(): void
    {
        $this->submit($this->inv1, ['billing_date' => '2024-12-31'])->assertSessionHasErrors('billing_date', errorBag: "invoice-{$this->inv1}");
        $this->submit($this->inv1, ['pph_qualification' => 'A', 'billing_date' => '2026-08-20'])->assertSessionHasNoErrors();

        DB::table('tax_rates')->where('id', $this->pphB)->update(['is_active' => false]);
        $this->submit($this->inv2, ['invoice_number' => 'X-2'])->assertSessionHasErrors('pph_qualification', errorBag: "invoice-{$this->inv2}"); // PPh hilang tidak pernah dianggap 0
        $row = $this->row($this->inv2);
        $this->assertSame(['PLANNED', null, 1, 1], [$row->workflow_status, $row->pph_rate_snapshot, (int) $row->version, $this->audits()]);
        $this->assertSame(0, $this->docs($this->inv2));
    }

    public function test_snapshot_is_locked_after_submit(): void
    {
        $this->submit($this->inv1)->assertSessionHasNoErrors();
        $stored = (array) $this->row($this->inv1);

        $this->rate('PPN', null, '12.00', '2026-09-01');
        DB::table('tax_rates')->where('id', $this->ppn11)->update(['effective_until' => '2026-08-31']);
        $this->submit($this->inv1, ['version' => 2, 'billing_date' => '2026-09-10', 'invoice_number' => 'INV/BM/2026/999'])->assertStatus(409);

        $this->assertSame($stored, (array) $this->row($this->inv1));
        $this->assertSame([1, 1], [$this->audits(), $this->docs($this->inv1)]);
    }

    public function test_invoice_number_is_unique_per_department(): void
    {
        $this->submit($this->inv1)->assertSessionHasNoErrors();

        $this->submit($this->inv2)->assertSessionHasErrors('invoice_number', errorBag: "invoice-{$this->inv2}");
        $row = $this->row($this->inv2);
        $this->assertSame(['PLANNED', 1, 1, 0], [$row->workflow_status, (int) $row->version, $this->audits(), $this->docs($this->inv2)]);
        $this->assertCount(1, Storage::disk('local')->allFiles()); // tidak ada berkas yatim dari percobaan gagal

        // Divisi lain boleh memakai nomor yang sama.
        $cpStaff = User::factory()->role('STAFF_PIC', 'CP')->create();
        $cp = Project::factory()->assignedTo($cpStaff)->create(['created_by' => $cpStaff->id, 'status' => 'ACTIVE', 'department' => 'CP']);
        $cpInvoice = $this->invoice($cp, 1);
        $this->submit($cpInvoice, [], $cpStaff)->assertSessionHasNoErrors();
        $this->assertSame('INVOICE_SUBMITTED', $this->row($cpInvoice)->workflow_status);
    }

    public function test_file_is_required_and_validated_like_documents(): void
    {
        $bad = [
            'missing' => null,
            'text file' => UploadedFile::fake()->create('invoice.txt', 10, 'text/plain'),
            'pdf renamed to png' => UploadedFile::fake()->create('invoice.png', 10, 'application/pdf'),
            'over 50 MB' => UploadedFile::fake()->create('invoice.pdf', 51201, 'application/pdf'),
        ];
        foreach ($bad as $why => $file) {
            $this->submit($this->inv1, ['file' => $file])->assertSessionHasErrors('file', errorBag: "invoice-{$this->inv1}");
            $this->untouched($this->inv1);
        }
        $this->submit($this->inv1, ['file' => UploadedFile::fake()->create('invoice.png', 10, 'image/png')])->assertSessionHasNoErrors();
        $this->assertSame('INVOICE_SUBMITTED', $this->row($this->inv1)->workflow_status);
    }

    public function test_required_fields_are_validated(): void
    {
        foreach (['invoice_number' => '', 'billing_date' => '', 'version' => '', 'pph_qualification' => ''] as $field => $value) {
            $this->submit($this->inv1, [$field => $value])->assertSessionHasErrors($field, errorBag: "invoice-{$this->inv1}");
        }
        $this->submit($this->inv1, ['billing_date' => '20/08/2026'])->assertSessionHasErrors('billing_date', errorBag: "invoice-{$this->inv1}");
        $this->submit($this->inv1, ['invoice_number' => str_repeat('a', 101)])->assertSessionHasErrors('invoice_number', errorBag: "invoice-{$this->inv1}");
        $this->untouched($this->inv1);
    }

    public function test_project_must_be_active_or_completed(): void
    {
        foreach (['DRAFT', 'SUBMITTED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->submit($this->inv1)->assertForbidden();
            $this->untouched($this->inv1);
        }
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->submit($this->inv1)->assertSessionHasNoErrors();
        $this->assertSame('INVOICE_SUBMITTED', $this->row($this->inv1)->workflow_status);
    }

    public function test_authorization_scope_unknown_ids_and_guest_for_submit_and_preview(): void
    {
        $preview = '?billing_date=2026-08-20&pph_qualification=B';
        $blocked = [
            [User::factory()->role('MANAGER_GM', 'BM')->create(), 403],
            [User::factory()->role('DIREKSI', null)->create(), 403],
            [User::factory()->role('SYSTEM_ADMIN', null)->create(), 403],
            [User::factory()->role('STAFF_PIC', 'BM')->create(), 404], // tidak ditugaskan
            [User::factory()->role('STAFF_PIC', 'CP')->create(), 404],
            [User::factory()->role('MANAGER_GM', 'CP')->create(), 404],
        ];
        foreach ($blocked as [$user, $code]) {
            $this->submit($this->inv1, [], $user)->assertStatus($code);
            $this->actingAs($user)->getJson("/invoices/{$this->inv1}/tax-preview$preview")->assertStatus($code);
        }
        $this->actingAs($this->staff)->post('/invoices/999999/submit', $this->payload())->assertNotFound();
        $this->actingAs($this->staff)->post('/invoices/99999999999999999999/submit', $this->payload())->assertNotFound();
        $this->actingAs($this->staff)->getJson("/invoices/999999/tax-preview$preview")->assertNotFound();
        $this->untouched($this->inv1);

        $this->app['auth']->forgetGuards();
        $this->post("/invoices/{$this->inv1}/submit", $this->payload())->assertRedirect('/login');
        $this->getJson("/invoices/{$this->inv1}/tax-preview$preview")->assertUnauthorized();
        $this->untouched($this->inv1);
    }

    public function test_stale_version_is_409_without_changes(): void
    {
        $this->actingAs($this->staff)->postJson("/invoices/{$this->inv1}/submit", $this->payload(['version' => 9]))->assertStatus(409);
        $this->submit($this->inv1, ['version' => 9])->assertRedirect()->assertSessionHasErrors('version', errorBag: "invoice-{$this->inv1}");
        $this->untouched($this->inv1);
    }

    public function test_only_planned_or_revision_required_can_be_submitted(): void
    {
        // CHECK database: baris non-PLANNED wajib lengkap; PAID wajib terkunci.
        $complete = ['invoice_number' => 'X', 'billing_date' => '2026-08-20', 'ppn_rate_snapshot' => '11', 'pph_rate_snapshot' => '2'];
        foreach (['INVOICE_SUBMITTED', 'INVOICE_VERIFIED', 'WAITING_FOR_PAYMENT', 'PAYMENT_SUBMITTED', 'PAYMENT_REVISION_REQUIRED', 'PAID', 'CANCELLED'] as $status) {
            DB::table('invoices')->where('id', $this->inv1)->update($complete + ['workflow_status' => $status, 'locked_at' => now(), 'locked_by' => $this->staff->id]);
            $this->actingAs($this->staff)->postJson("/invoices/{$this->inv1}/submit", $this->payload())->assertStatus(409);
        }
        $this->assertSame([0, 0], [$this->audits(), $this->docs($this->inv1)]);
    }

    public function test_due_date_is_required_not_before_billing_date_and_bounded_then_stored_and_audited(): void
    {
        $this->travelTo('2026-10-20 03:00:00');
        $bag = "invoice-{$this->inv1}";
        $this->submit($this->inv1, ['due_date' => null])->assertSessionHasErrors('due_date', errorBag: $bag);
        $this->submit($this->inv1, ['due_date' => '2026-08-19'])->assertSessionHasErrors('due_date', errorBag: $bag); // sebelum tanggal penagihan 2026-08-20
        $this->submit($this->inv1, ['due_date' => '2026-02-31'])->assertSessionHasErrors('due_date', errorBag: $bag);
        $this->submit($this->inv1, ['due_date' => '2028-10-21'])->assertSessionHasErrors('due_date', errorBag: $bag); // lebih dari dua tahun
        $this->submit($this->inv1, ['billing_date' => ['2026-08-20']])->assertSessionHasErrors('billing_date', errorBag: $bag); // billing_date bukan string tidak boleh menjatuhkan aturan due_date
        $this->untouched($this->inv1);

        $this->submit($this->inv1, ['due_date' => '2026-08-20'])->assertSessionHasNoErrors(); // sama dengan tanggal penagihan boleh
        $this->assertSame('2026-08-20', $this->row($this->inv1)->due_date);
        $this->submit($this->inv2, ['due_date' => '2028-10-20', 'invoice_number' => 'INV/BM/2026/079'])->assertSessionHasNoErrors(); // tepat batas atas
        $this->assertSame('2028-10-20', $this->row($this->inv2)->due_date);
        $audit = DB::table('audit_logs')->where('entity_type', 'INVOICE')->where('entity_id', $this->inv1)->sole();
        $this->assertSame([null, '2026-08-20'], [json_decode($audit->data_before)->due_date, json_decode($audit->data_after)->due_date]);
    }

    public function test_resubmit_after_revision_recomputes_and_replaces_the_file_keeping_history(): void
    {
        $this->submit($this->inv1)->assertSessionHasNoErrors();
        $first = DB::table('documents')->where('entity_id', $this->inv1)->sole();
        DB::table('invoices')->where('id', $this->inv1)->update(['workflow_status' => 'INVOICE_REVISION_REQUIRED', 'revision_reason' => 'Nomor salah']); // diputuskan INV-02

        $this->submit($this->inv1, ['version' => 2, 'invoice_number' => 'INV/BM/2026/078-R', 'pph_qualification' => 'A', 'billing_date' => '2026-09-02', 'due_date' => null])->assertSessionHasErrors('due_date', errorBag: "invoice-{$this->inv1}"); // pengajuan ulang juga wajib due_date
        $this->submit($this->inv1, ['version' => 2, 'invoice_number' => 'INV/BM/2026/078-R', 'pph_qualification' => 'A', 'billing_date' => '2026-09-02', 'due_date' => '2026-10-02'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-02', $this->row($this->inv1)->due_date);

        $row = $this->row($this->inv1);
        $this->assertSame(['INVOICE_SUBMITTED', 'INV/BM/2026/078-R', '1.7500', '5290609.52', '330285194.49', 3],
            [$row->workflow_status, $row->invoice_number, $row->pph_rate_snapshot, $row->pph_amount, $row->net_received_amount, (int) $row->version]);

        $docs = DB::table('documents')->where('entity_id', $this->inv1)->orderBy('id')->get();
        $this->assertSame(['REPLACED', 'UPLOADED', (int) $first->id], [$docs[0]->status, $docs[1]->status, (int) $docs[1]->replaced_document_id]);
        Storage::disk('local')->assertExists($first->storage_key); // file lama tetap tersimpan
        Storage::disk('local')->assertExists($docs[1]->storage_key);
        $this->assertSame(2, DB::table('audit_logs')->where('entity_type', 'INVOICE')->where('action', 'SUBMIT')->count());
    }

    public function test_failure_after_the_file_is_stored_rolls_everything_back(): void
    {
        DB::listen(fn ($query) => str_contains($query->sql, 'insert into `audit_logs`') && throw new \RuntimeException('audit gagal'));

        $this->withoutExceptionHandling();
        try {
            $this->submit($this->inv1);
            $this->fail('Harus gagal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit gagal', $e->getMessage());
        }

        $this->untouched($this->inv1); // baris invoice, dokumen, dan berkas di disk kembali seperti semula
    }

    public function test_project_page_offers_the_form_and_shows_stored_figures(): void
    {
        $page = "/projects/{$this->project->id}";
        $this->actingAs($this->staff)->get($page)->assertOk()->assertSee('Ajukan invoice')->assertSee('name="_modal" value="invoice-'.$this->inv1.'"', false)->assertSee('Rencana tagihan');
        $this->actingAs(User::factory()->role('MANAGER_GM', 'BM')->create())->get($page)->assertOk()->assertDontSee('Ajukan invoice');

        $this->submit($this->inv1)->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->get($page)->assertOk()->assertSee('INV/BM/2026/078')->assertSee('Rp302.320.544,15')->assertSee('Rp327.564.309,59')->assertSee('PPh (2,65%)', false)
            ->assertDontSee('name="_modal" value="invoice-'.$this->inv1.'"', false)->assertSee('name="_modal" value="invoice-'.$this->inv2.'"', false);

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'DRAFT']);
        $this->actingAs($this->staff)->get($page)->assertOk()->assertDontSee('Ajukan invoice');
    }

    public function test_failed_submit_rerenders_the_form_open_with_the_error(): void
    {
        $page = "/projects/{$this->project->id}";
        $html = $this->actingAs($this->staff)->followingRedirects()->from($page)->post("/invoices/{$this->inv1}/submit", $this->payload(['invoice_number' => '<b>x</b>', 'billing_date' => '2024-12-31']))->assertOk();
        $html->assertSee('Tarif PPN yang berlaku pada tanggal penagihan belum ada.')->assertSee('&lt;b&gt;x&lt;/b&gt;', false)->assertDontSee('<b>x</b>', false);
        $this->assertSame(["invoice-{$this->inv1}"], $this->openModals($html->getContent())); // hanya popup termin yang gagal dibuka ulang, tanpa banner galat halaman
        $html->assertSee('enctype="multipart/form-data"', false);
    }

    public function test_billing_date_cannot_be_more_than_one_year_ahead(): void
    {
        $limit = Carbon::today('Asia/Jakarta')->addYearNoOverflow();
        $this->rate('PPN', null, '12.00', '2026-09-01'); // tarif terbuka: tanpa batas atas, tanggal jauh tetap "berlaku"

        foreach (['9999-12-31', '2062-01-01', $limit->copy()->addDay()->toDateString()] as $date) {
            $this->submit($this->inv1, ['billing_date' => $date])->assertSessionHasErrors('billing_date', errorBag: "invoice-{$this->inv1}");
            $this->actingAs($this->staff)->getJson("/invoices/{$this->inv1}/tax-preview?billing_date=$date&pph_qualification=B")->assertStatus(422)->assertJsonValidationErrors('billing_date');
        }
        $this->untouched($this->inv1);

        $this->actingAs($this->staff)->getJson("/invoices/{$this->inv1}/tax-preview?billing_date={$limit->toDateString()}&pph_qualification=B")->assertOk();
        $this->submit($this->inv1, ['billing_date' => $limit->toDateString(), 'due_date' => $limit->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('INVOICE_SUBMITTED', $this->row($this->inv1)->workflow_status);
    }
}
