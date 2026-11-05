<?php

namespace Tests\Feature\Master;

use App\Enums\PksStatus;
use App\Models\BouwheerContact;
use App\Models\Pks;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// PRD 16.2.2, 16.2.3, FD-17: PKS dan PIC Bouwheer adalah data master milik Staff/PIC dari divisi mana pun.
class PksAndContactTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
    }

    private function pksForm(array $overrides = []): array
    {
        return $overrides + ['pks_number' => 'PKS/2026/001', 'name' => 'Pengelolaan Gedung Menara A', 'client_name' => 'PT Bouwheer Sejahtera', 'issued_date' => '2026-01-05', 'start_date' => '2026-02-01', 'end_date' => '2027-01-31', 'status' => 'ACTIVE'];
    }

    private function contactForm(array $overrides = []): array
    {
        return $overrides + ['name' => 'Hendra Wijaya', 'company_name' => 'PT Bouwheer Sejahtera', 'position' => 'Project Manager', 'phone_number' => '0812-0000-111', 'email' => 'hendra@example.com', 'is_active' => '1'];
    }

    public function test_only_staff_pic_can_manage_master_data(): void
    {
        // Baris sudah ada, supaya PUT/edit ditolak karena role, bukan karena baris tidak ditemukan.
        $this->actingAs($this->staff)->post('/master/pks', $this->pksForm());
        $this->post('/master/bouwheer-contacts', $this->contactForm());
        $this->app['auth']->forgetGuards();
        $pks = Pks::sole();
        $contact = BouwheerContact::sole();
        $snapshot = [$pks->toArray(), $contact->toArray(), DB::table('audit_logs')->count()];

        // Tamu diarahkan ke login dan tidak mengubah apa pun.
        $this->get('/master/pks')->assertRedirect('/login');
        $this->get('/master/bouwheer-contacts')->assertRedirect('/login');
        $this->post('/master/pks', $this->pksForm(['pks_number' => 'PKS/GUEST']))->assertRedirect('/login');
        $this->put("/master/pks/{$pks->id}", $this->pksForm(['name' => 'Diubah tamu']))->assertRedirect('/login');
        $this->post('/master/bouwheer-contacts', $this->contactForm(['name' => 'Tamu']))->assertRedirect('/login');
        $this->put("/master/bouwheer-contacts/{$contact->id}", $this->contactForm(['name' => 'Diubah tamu']))->assertRedirect('/login');

        foreach ([['SYSTEM_ADMIN', 'ITBS'], ['MANAGER_GM', 'BM'], ['DIREKSI', null]] as [$role, $department]) {
            $user = User::factory()->role($role, $department)->create();
            $this->actingAs($user);
            foreach (['/master/pks', '/master/pks/create', "/master/pks/{$pks->id}/edit", '/master/bouwheer-contacts', '/master/bouwheer-contacts/create', "/master/bouwheer-contacts/{$contact->id}/edit"] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/master/pks', $this->pksForm(['pks_number' => 'PKS/X']))->assertForbidden();
            $this->put("/master/pks/{$pks->id}", $this->pksForm(['name' => 'Diubah']))->assertForbidden();
            $this->post('/master/bouwheer-contacts', $this->contactForm(['name' => 'X']))->assertForbidden();
            $this->put("/master/bouwheer-contacts/{$contact->id}", $this->contactForm(['name' => 'Diubah']))->assertForbidden();
        }

        $this->assertSame($snapshot, [Pks::sole()->toArray(), BouwheerContact::sole()->toArray(), DB::table('audit_logs')->count()]);
    }

    public function test_staff_pic_of_any_division_can_manage_master_data(): void
    {
        foreach (['BM', 'ITBS'] as $i => $department) {
            $staff = User::factory()->role('STAFF_PIC', $department)->create();
            $this->actingAs($staff)->get('/master/pks')->assertOk();
            $this->get('/master/bouwheer-contacts')->assertOk();
            $this->post('/master/pks', $this->pksForm(['pks_number' => "PKS/D{$i}"]))->assertRedirect('/master/pks');
            $this->post('/master/bouwheer-contacts', $this->contactForm(['name' => "PIC {$department}"]))->assertRedirect('/master/bouwheer-contacts');
            $this->assertSame($staff->id, Pks::where('pks_number', "PKS/D{$i}")->value('created_by'));
        }

        // Staff divisi lain boleh mengubah data yang dibuat staff divisi berbeda (PKS tidak punya divisi).
        $pks = Pks::where('pks_number', 'PKS/D0')->sole();
        $contact = BouwheerContact::where('name', 'PIC BM')->sole();
        $this->put("/master/pks/{$pks->id}", $this->pksForm(['pks_number' => 'PKS/D0', 'name' => 'Diubah ITBS']))->assertRedirect('/master/pks');
        $this->put("/master/bouwheer-contacts/{$contact->id}", $this->contactForm(['name' => 'PIC BM', 'position' => 'Diubah ITBS']))->assertRedirect('/master/bouwheer-contacts');
        $this->assertSame(['Diubah ITBS', 'Diubah ITBS'], [$pks->fresh()->name, $contact->fresh()->position]);
        $this->assertSame(2, BouwheerContact::count());
    }

    public function test_extra_body_fields_are_ignored(): void
    {
        $other = User::factory()->role('STAFF_PIC', 'CP')->create();
        $this->actingAs($this->staff)->post('/master/pks', $this->pksForm(['id' => 9999, 'created_by' => $other->id]))->assertRedirect('/master/pks');
        $this->post('/master/bouwheer-contacts', $this->contactForm(['id' => 9999, 'created_by' => $other->id]))->assertRedirect('/master/bouwheer-contacts');

        $pks = Pks::sole();
        $contact = BouwheerContact::sole();
        $this->assertNotSame(9999, $pks->id);
        $this->assertNotSame(9999, $contact->id);
        $this->assertSame($this->staff->id, $pks->created_by);

        $this->put("/master/pks/{$pks->id}", $this->pksForm(['id' => 9999, 'created_by' => $other->id, 'name' => 'Diubah']))->assertRedirect('/master/pks');
        $this->put("/master/bouwheer-contacts/{$contact->id}", $this->contactForm(['id' => 9999, 'created_by' => $other->id, 'position' => 'Diubah']))->assertRedirect('/master/bouwheer-contacts');

        $this->assertSame([$pks->id, $this->staff->id, 'Diubah'], [Pks::sole()->id, Pks::sole()->created_by, Pks::sole()->name]);
        $this->assertSame([$contact->id, 'Diubah'], [BouwheerContact::sole()->id, BouwheerContact::sole()->position]);
    }

    public function test_staff_creates_and_updates_pks_with_audit_log(): void
    {
        $this->actingAs($this->staff)->get('/master/pks/create')->assertOk();
        $this->post('/master/pks', $this->pksForm())->assertRedirect('/master/pks')->assertSessionHas('status');

        $pks = Pks::sole();
        $this->assertSame(['PKS/2026/001', PksStatus::ACTIVE, $this->staff->id, '2027-01-31'], [$pks->pks_number, $pks->status, $pks->created_by, $pks->end_date->format('Y-m-d')]);

        $this->get("/master/pks/{$pks->id}/edit")->assertOk()->assertSee('PKS/2026/001');
        $this->put("/master/pks/{$pks->id}", $this->pksForm(['name' => 'Pengelolaan Gedung Menara A dan B', 'status' => 'ARCHIVED']))->assertRedirect('/master/pks');

        $this->assertSame(['Pengelolaan Gedung Menara A dan B', PksStatus::ARCHIVED], [$pks->fresh()->name, $pks->fresh()->status]);
        $logs = DB::table('audit_logs')->where('entity_type', 'PKS')->orderBy('id')->get();
        $this->assertSame(['CREATE', 'UPDATE'], $logs->pluck('action')->all());
        $this->assertSame([$this->staff->id, $this->staff->id], $logs->pluck('user_id')->all());
        $created = json_decode($logs[0]->data_after);
        $this->assertSame(['PKS/2026/001', 'Pengelolaan Gedung Menara A', 'ACTIVE'], [$created->pks_number, $created->name, $created->status]);
        $this->assertSame(['ACTIVE', 'ARCHIVED', '2027-01-31'], [json_decode($logs[1]->data_before)->status, json_decode($logs[1]->data_after)->status, json_decode($logs[1]->data_after)->end_date]);
    }

    public function test_pks_validation(): void
    {
        $this->actingAs($this->staff)->post('/master/pks', $this->pksForm());

        $this->post('/master/pks', $this->pksForm())->assertInvalid(['pks_number' => 'sudah dipakai']);
        $this->post('/master/pks', $this->pksForm(['pks_number' => 'PKS/2', 'end_date' => '2026-01-31']))->assertInvalid(['end_date' => 'tidak boleh lebih awal']);
        $this->post('/master/pks', $this->pksForm(['pks_number' => 'PKS/3', 'status' => 'REJECTED']))->assertInvalid('status');
        $this->post('/master/pks', $this->pksForm(['pks_number' => '', 'name' => '', 'client_name' => '', 'issued_date' => '']))->assertInvalid(['pks_number', 'name', 'client_name', 'issued_date']);
        $this->post('/master/pks', $this->pksForm(['pks_number' => 'PKS/4', 'start_date' => null, 'end_date' => null]))->assertValid(); // periode boleh kosong

        // Nomor sendiri boleh tetap saat diubah.
        $this->put('/master/pks/'.Pks::where('pks_number', 'PKS/2026/001')->value('id'), $this->pksForm())->assertValid();
        $this->assertSame(2, Pks::count());
    }

    public function test_pks_list_searches_and_counts_projects_and_pks_cannot_be_deleted(): void
    {
        $project = Project::factory()->create();
        $used = Pks::findOrFail($project->pks_id);
        $this->actingAs($this->staff)->post('/master/pks', $this->pksForm());

        $this->get('/master/pks')->assertOk()->assertSee($used->pks_number)->assertSee('PKS/2026/001');
        $this->get('/master/pks?q=Menara')->assertSee('PKS/2026/001')->assertDontSee($used->pks_number);
        $this->assertSame(1, Pks::withCount('projects')->find($used->id)->projects_count);

        $this->delete("/master/pks/{$used->id}")->assertStatus(405); // tidak ada route hapus
        $this->assertNotNull($used->fresh());
    }

    public function test_staff_creates_updates_and_deactivates_bouwheer_contact(): void
    {
        $this->actingAs($this->staff)->get('/master/bouwheer-contacts/create')->assertOk();
        $this->post('/master/bouwheer-contacts', $this->contactForm())->assertRedirect('/master/bouwheer-contacts');

        $contact = BouwheerContact::sole();
        $this->assertTrue($contact->is_active);
        $this->get('/master/bouwheer-contacts')->assertSee('Hendra Wijaya')->assertSee('Aktif');
        $this->get('/master/bouwheer-contacts?q=tidak-ada')->assertSee('Tidak ada PIC yang cocok');

        // Checkbox tidak dicentang berarti nonaktif.
        $form = $this->contactForm(['position' => 'Direktur Operasional']);
        unset($form['is_active']);
        $this->put("/master/bouwheer-contacts/{$contact->id}", $form)->assertRedirect('/master/bouwheer-contacts');

        $this->assertSame(['Direktur Operasional', false], [$contact->fresh()->position, $contact->fresh()->is_active]);
        $this->get('/master/bouwheer-contacts')->assertSee('Nonaktif');
        $created = DB::table('audit_logs')->where('entity_type', 'BOUWHEER_CONTACT')->where('action', 'CREATE')->sole();
        $this->assertSame([$contact->id, $this->staff->id, 'Hendra Wijaya', true], [$created->entity_id, $created->user_id, json_decode($created->data_after)->name, json_decode($created->data_after)->is_active]);
        $log = DB::table('audit_logs')->where('entity_type', 'BOUWHEER_CONTACT')->where('action', 'UPDATE')->sole();
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame([true, false], [json_decode($log->data_before)->is_active, json_decode($log->data_after)->is_active]);

        $this->delete("/master/bouwheer-contacts/{$contact->id}")->assertStatus(405);
    }

    public function test_contact_validation(): void
    {
        $this->actingAs($this->staff);

        $this->post('/master/bouwheer-contacts', $this->contactForm(['name' => '', 'company_name' => '']))->assertInvalid(['name', 'company_name']);
        $this->post('/master/bouwheer-contacts', $this->contactForm(['email' => 'bukan-email']))->assertInvalid(['email' => 'tidak valid']);
        $this->post('/master/bouwheer-contacts', $this->contactForm(['phone_number' => null, 'email' => null]))->assertValid(); // keduanya hanya dianjurkan

        $this->assertSame(1, BouwheerContact::count());
    }

    public function test_master_tabs_link_only_pages_the_role_may_open(): void
    {
        $this->actingAs($this->staff)->get('/master/pks')->assertOk()
            ->assertSee('href="'.route('master.pks.index').'"', false)
            ->assertSee('href="'.route('master.bouwheer-contacts.index').'"', false)
            ->assertDontSee('href="'.route('master.users.index').'"', false);

        $admin = User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create();
        $this->actingAs($admin)->get('/master/users')->assertOk()
            ->assertDontSee('href="'.route('master.pks.index').'"', false)
            ->assertDontSee('href="'.route('master.bouwheer-contacts.index').'"', false);
    }
}
