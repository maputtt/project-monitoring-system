<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\Notice;
use App\Support\Notify;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

// Story E: notifikasi lonceng + email. Jaminan PM: penerima hanya GM divisi (menunggu keputusan), PIC aktif ber-assignment di divisi yang sama (sudah diputuskan),
// admin (tiket baru) dan pelapor (tiket diperbarui). Setiap tes memeriksa himpunan penerima PERSIS terhadap fixture penuh.
// Tanpa langganan push semua user menerima email; cabang push diuji di PushSubscriptionTest.
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $u = [];

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutDefer(); // email dikirim langsung, bukan setelah respons
        Storage::fake('local');

        $make = fn (string $label, string $role, ?string $dept, array $extra = []) => $this->u[$label] = User::factory()->role($role, $dept)->create($extra + ['name' => $label]);
        $make('PIC1', 'STAFF_PIC', 'BM');
        $make('PIC2', 'STAFF_PIC', 'BM');
        $make('PIC3', 'STAFF_PIC', 'BM'); // tidak ditugaskan
        $make('PIC4', 'STAFF_PIC', 'BM', ['is_active' => false]); // akun nonaktif, assignment aktif
        $make('PIC5', 'STAFF_PIC', 'BM'); // assignment nonaktif
        $make('GMA', 'MANAGER_GM', 'BM');
        $make('GMA2', 'MANAGER_GM', 'BM', ['is_active' => false]);
        $make('GMB', 'MANAGER_GM', 'CP');
        $make('PICB', 'STAFF_PIC', 'CP'); // dipaksa ditugaskan lintas divisi
        $make('DIREKSI', 'DIREKSI', null);
        $make('ADMIN', 'SYSTEM_ADMIN', null);
        $make('ADMIN2', 'SYSTEM_ADMIN', null, ['is_active' => false]);

        $this->project = Project::factory()->assignedTo($this->u['PIC1'])->create(['created_by' => $this->u['PIC1']->id, 'status' => 'ACTIVE']);
        foreach (['PIC2' => true, 'PIC4' => true, 'PIC5' => false, 'PICB' => true] as $label => $active) {
            DB::table('project_user_assignments')->insert(['project_id' => $this->project->id, 'user_id' => $this->u[$label]->id, 'assignment_role' => 'PIC', 'assigned_by' => $this->u['GMA']->id, 'is_active' => $active]);
        }
        DB::table('tax_rates')->insert([
            ['tax_type' => 'PPN', 'name' => 'PPN 11', 'vendor_qualification' => null, 'rate' => '11.00', 'effective_from' => '2025-01-01', 'created_by' => $this->u['PIC1']->id],
            ['tax_type' => 'PPH', 'name' => 'PPH B', 'vendor_qualification' => 'B', 'rate' => '2.65', 'effective_from' => '2025-01-01', 'created_by' => $this->u['PIC1']->id],
        ]);
    }

    /** Label penerima dari baris notifikasi (urut), setelah memastikan isi tiap notifikasi aman. */
    private function bell(): array
    {
        $rows = DB::table('notifications')->get();
        foreach ($rows as $row) {
            $data = json_decode($row->data, true);
            $this->assertSame(['title', 'url'], array_keys($data));
            $this->assertStringNotContainsString('Rp', $data['title']);
            $this->assertMatchesRegularExpression('#^/[^/]#', $data['url']); // path relatif, bukan URL penuh
            $this->assertSame(Notice::class, $row->type);
        }

        return $this->labels($rows->pluck('notifiable_id')->all());
    }

    private function mailed(): array
    {
        $ids = [];
        foreach (app('mail.manager')->mailer()->getSymfonyTransport()->messages() as $sent) {
            foreach ($sent->getEnvelope()->getRecipients() as $to) {
                $ids[] = User::where('email', $to->getAddress())->value('id');
            }
        }

        return $this->labels($ids);
    }

    private function labels(array $ids): array
    {
        $names = collect($this->u)->mapWithKeys(fn (User $u, string $l) => [$u->id => $l]);
        $out = array_map(fn ($id) => $names[$id], $ids);
        sort($out);

        return $out;
    }

    /** Jalankan aksi; bell dan email harus sampai ke himpunan PERSIS ini, lalu bersihkan untuk aksi berikutnya. */
    private function expect(array $who, Closure $act): void
    {
        $this->clear();
        $response = $act();
        $response->assertSessionHasNoErrors();
        sort($who);
        $this->assertSame($who, $this->bell(), 'bell');
        $this->assertSame($who, $this->mailed(), 'email');
        $this->clear();
    }

    private function clear(): void
    {
        DB::table('notifications')->delete();
        app('mail.manager')->mailer()->getSymfonyTransport()->flush();
    }

    private function set(string $table, int $id, array $values): void
    {
        DB::table($table)->where('id', $id)->update($values);
    }

    private function bq(string $status): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => 'Item', 'item_type' => 'NORMAL', 'status' => $status, 'created_by' => $this->u['PIC1']->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
        ]);
    }

    private function invoice(string $status, bool $docs = true, int $seq = 1): int
    {
        $paid = $status === 'PAID' ? ['locked_at' => now(), 'locked_by' => $this->u['GMA']->id] : [];
        $id = DB::table('invoices')->insertGetId($paid + [
            'invoice_number' => 'INV/1', 'billing_date' => '2026-08-20', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500',
            'dpp_amount' => '100.00', 'ppn_amount' => '11.00', 'subtotal_amount' => '111.00', 'pph_amount' => '3.00', 'net_received_amount' => '108.00',
            'submitted_by' => $this->u['PIC1']->id, 'submitted_at' => now(),
            'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000',
            'planned_amount' => '111.00', 'workflow_status' => $status, 'created_by' => $this->u['PIC1']->id,
        ]);
        $docs && collect(['INVOICE_FILE', 'BUKTI_BAYAR'])->each(fn ($type) => DB::table('documents')->insert([
            'project_id' => $this->project->id, 'entity_type' => 'INVOICE', 'entity_id' => $id, 'document_type' => $type, 'original_filename' => 'f.pdf',
            'storage_key' => "k/$id/$type", 'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by' => $this->u['PIC1']->id,
        ]));

        return $id;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('f.pdf', 50, 'application/pdf');
    }

    private function as(string $label): static
    {
        return $this->actingAs($this->u[$label]);
    }

    // --- Penerima per endpoint (himpunan persis) ---

    public function test_project_workflow_notifies_exactly_the_right_people(): void
    {
        $id = $this->project->id;
        $this->set('projects', $id, ['status' => 'DRAFT']);
        $this->expect(['GMA'], fn () => $this->as('PIC1')->post("/projects/$id/submit"));
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/projects/$id/request-revision", ['reason' => 'Termin salah']));
        $this->set('projects', $id, ['status' => 'SUBMITTED']);
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/projects/$id/activate"));
        $this->expect([], fn () => $this->as('GMA')->post("/projects/$id/complete")); // selesai tidak diberitahukan
    }

    public function test_bq_workflow_notifies_exactly_the_right_people(): void
    {
        $bq = $this->bq('DRAFT');
        $this->expect(['GMA'], fn () => $this->as('PIC1')->post("/bq-items/$bq/submit"));
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/bq-items/$bq/request-revision", ['reason' => 'Harga belum jelas']));
        $this->set('bq_items', $bq, ['status' => 'SUBMITTED']);
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/bq-items/$bq/approve"));
    }

    public function test_invoice_workflow_notifies_exactly_the_right_people(): void
    {
        $inv = $this->invoice('PLANNED', docs: false);
        $submit = fn () => $this->as('PIC1')->post("/invoices/$inv/submit", ['version' => 1, 'invoice_number' => 'INV/BM/1', 'billing_date' => '2026-08-20', 'due_date' => '2026-12-31', 'pph_qualification' => 'B', 'file' => $this->pdf()]);
        $this->expect(['GMA'], $submit);

        $inv = $this->invoice('INVOICE_SUBMITTED', seq: 2); // pengaju PIC1
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/invoices/$inv/verify"));
        $this->set('invoices', $inv, ['workflow_status' => 'INVOICE_SUBMITTED']);
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/invoices/$inv/request-revision", ['reason' => 'Nomor salah']));

        $this->set('invoices', $inv, ['workflow_status' => 'WAITING_FOR_PAYMENT']);
        $this->expect(['GMA'], fn () => $this->as('PIC1')->post("/invoices/$inv/submit-payment", ['file' => $this->pdf()]));

        $this->set('invoices', $inv, ['workflow_status' => 'PAYMENT_SUBMITTED']);
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/invoices/$inv/approve-payment"));
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/invoices/$inv/unlock", ['reason' => 'Nominal salah'])); // keputusan PM: unlock = perlu revisi
    }

    public function test_adjustment_workflow_notifies_exactly_the_right_people(): void
    {
        $id = $this->project->id;
        $this->expect(['GMA'], fn () => $this->as('PIC1')->post("/projects/$id/adjustments", ['file' => $this->pdf(), 'margin_mode' => 'PCT', 'margin_value' => '10', 'reason' => 'Nego ulang']));
        $adj = DB::table('project_adjustments')->value('id');
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/adjustments/$adj/request-revision", ['reason' => 'Lengkapi']));
        $this->expect(['GMA'], fn () => $this->as('PIC1')->put("/adjustments/$adj", ['margin_mode' => 'PCT', 'margin_value' => '9', 'reason' => 'Revisi']));
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/adjustments/$adj/approve"));
    }

    public function test_budget_mutation_workflow_notifies_exactly_the_right_people(): void
    {
        $dest = $this->bq('APPROVED');
        $payload = fn () => ['mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => $dest, 'amount' => '100.00', 'reason' => 'Butuh tambahan', 'file' => $this->pdf()];
        $this->expect(['GMA'], fn () => $this->as('PIC1')->post("/projects/{$this->project->id}/budget-mutations", $payload()));
        $mutation = DB::table('bq_budget_mutations')->value('id');
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/budget-mutations/$mutation/request-revision", ['reason' => 'Lengkapi']));
        $this->expect(['GMA'], fn () => $this->as('PIC1')->put("/budget-mutations/$mutation", $payload()));
        $this->expect(['PIC1', 'PIC2'], fn () => $this->as('GMA')->post("/budget-mutations/$mutation/approve"));
    }

    public function test_ticket_events_reach_only_admins_and_the_reporter(): void
    {
        $this->expect(['ADMIN'], fn () => $this->as('PIC1')->post('/support-tickets', ['title' => 'Tombol error', 'description' => 'Tidak bisa simpan']));
        $ticket = SupportTicket::sole();

        $this->expect(['PIC1'], fn () => $this->as('ADMIN')->put("/support-tickets/{$ticket->id}", ['status' => 'IN_PROGRESS', 'admin_note' => '']));
        $this->expect(['PIC1'], fn () => $this->as('ADMIN')->put("/support-tickets/{$ticket->id}", ['status' => 'IN_PROGRESS', 'admin_note' => 'Sedang dicek']));
        $this->expect([], fn () => $this->as('ADMIN')->put("/support-tickets/{$ticket->id}", ['status' => 'IN_PROGRESS', 'admin_note' => 'Sedang dicek'])); // tidak ada perubahan

        $this->set('users', $this->u['PIC1']->id, ['is_active' => false]); // pelapor nonaktif: tidak ada yang menerima
        $this->expect([], fn () => $this->as('ADMIN')->put("/support-tickets/{$ticket->id}", ['status' => 'RESOLVED', 'admin_note' => '']));
        $this->expect([], fn () => $this->as('ADMIN')->post('/support-tickets', ['title' => 'Admin lapor', 'description' => 'x'])); // pelapor admin = aktor, admin lain nonaktif
    }

    // --- Fungsi penerima ---

    public function test_recipients_function_for_every_event_excludes_actor_and_outsiders(): void
    {
        $p = $this->project;
        $who = fn ($users) => $this->labels($users->pluck('id')->all());

        $this->assertSame(['GMA'], $who(Notify::recipients('awaiting', $p, $this->u['PIC1'])));
        $this->assertSame([], $who(Notify::recipients('awaiting', $p, $this->u['GMA']))); // aktor dibuang
        $this->assertSame(['PIC1', 'PIC2'], $who(Notify::recipients('decided', $p, $this->u['GMA'])));
        $this->assertSame(['PIC2'], $who(Notify::recipients('decided', $p, $this->u['PIC1']))); // aktor dibuang
        foreach (['awaiting', 'decided'] as $event) { // tak satu pun dari luar lingkup
            $this->assertEmpty(array_intersect($who(Notify::recipients($event, $p, $this->u['DIREKSI'])), ['PIC3', 'PIC4', 'PIC5', 'PICB', 'GMA2', 'GMB', 'DIREKSI', 'ADMIN', 'ADMIN2']), $event);
        }

        $ticket = SupportTicket::create(['reporter_id' => $this->u['PIC1']->id, 'title' => 'T', 'description' => 'D', 'status' => 'NEW']);
        $this->assertSame(['ADMIN'], $who(Notify::recipients('ticket_created', $ticket, $this->u['PIC1'])));
        $this->assertSame([], $who(Notify::recipients('ticket_created', $ticket, $this->u['ADMIN']))); // admin pelapor = aktor
        $this->assertSame(['PIC1'], $who(Notify::recipients('ticket_updated', $ticket, $this->u['ADMIN'])));
        $this->assertSame([], $who(Notify::recipients('ticket_updated', $ticket, $this->u['PIC1'])));

        $this->expectException(InvalidArgumentException::class);
        Notify::recipients('whatever', $p, $this->u['PIC1']);
    }

    // --- Rollback, isolasi kegagalan ---

    public function test_no_notification_after_a_rolled_back_action(): void
    {
        $id = $this->project->id;
        $this->set('projects', $id, ['status' => 'SUBMITTED']);
        $this->set('bq_items', $this->bq('DRAFT'), ['effective_budget' => '999999999999.00']); // alokasi melebihi RAB: aktivasi dibatalkan
        $this->clear();

        $this->as('GMA')->post("/projects/$id/activate")->assertSessionHasErrors('project');

        $this->assertSame('SUBMITTED', DB::table('projects')->where('id', $id)->value('status'));
        $this->assertSame([[], []], [$this->bell(), $this->mailed()]);
    }

    public function test_mail_failure_never_breaks_the_action_and_keeps_the_bell(): void
    {
        app('mail.manager')->extend('boom', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new RuntimeException('SMTP down');
            }

            public function __toString(): string
            {
                return 'boom';
            }
        });
        config(['mail.default' => 'boom', 'mail.mailers.boom' => ['transport' => 'boom']]);
        $id = $this->project->id;
        $this->set('projects', $id, ['status' => 'DRAFT']);

        $this->as('PIC1')->post("/projects/$id/submit")->assertRedirect("/projects/$id")->assertSessionHasNoErrors();

        $this->assertSame('SUBMITTED', DB::table('projects')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', 'PROJECT')->where('action', 'SUBMIT')->count());
        $this->assertSame(['GMA'], $this->bell());
    }

    public function test_mail_title_is_plain_text_even_with_markdown_link_or_html(): void
    {
        $html = (new Notice('[Klik](http://evil.example) <script>alert(1)</script> *b*', '/projects/1'))->toMail($this->u['GMA'])->render();

        $this->assertStringNotContainsString('href="http://evil.example', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<em', $html);
        $this->assertStringContainsString('[Klik](http://evil.example) &lt;script&gt;alert(1)&lt;/script&gt; *b*', $html);
    }

    // --- Lonceng: buka dan tandai dibaca ---

    private function notice(string $label, string $url = '/projects/1'): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'type' => Notice::class, 'notifiable_type' => User::class, 'notifiable_id' => $this->u[$label]->id,
            'data' => json_encode(['title' => 'Judul', 'url' => $url]), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_open_marks_own_notification_read_and_redirects_to_its_path(): void
    {
        $id = $this->notice('GMA', "/projects/{$this->project->id}");

        $this->as('GMA')->get("/notifications/$id/open")->assertRedirect("/projects/{$this->project->id}");

        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_opening_someone_elses_notification_is_404_and_leaves_it_unread(): void
    {
        $id = $this->notice('GMA');

        $this->as('PIC1')->get("/notifications/$id/open")->assertNotFound();
        $this->as('ADMIN')->get("/notifications/$id/open")->assertNotFound();
        $this->as('GMA')->get('/notifications/not-a-uuid/open')->assertNotFound();
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        auth()->logout();
        $this->get("/notifications/$id/open")->assertRedirect('/login');
    }

    public function test_open_never_redirects_outside_the_app(): void
    {
        foreach (['//evil.example/x', 'https://evil.example/x', '/\\evil.example', 'javascript:alert(1)', ''] as $url) {
            $id = $this->notice('GMA', $url);
            $this->as('GMA')->get("/notifications/$id/open")->assertRedirect('/dashboard');
            $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        }
    }

    public function test_read_all_only_touches_own_notifications(): void
    {
        $mine = [$this->notice('GMA'), $this->notice('GMA')];
        $theirs = $this->notice('PIC1');

        $this->as('GMA')->post('/notifications/read-all')->assertRedirect();

        $this->assertSame(0, DB::table('notifications')->whereIn('id', $mine)->whereNull('read_at')->count());
        $this->assertNull(DB::table('notifications')->where('id', $theirs)->value('read_at'));
        auth()->logout();
        $this->post('/notifications/read-all')->assertRedirect('/login');
    }
}
