<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->role('STAFF_PIC', 'BM')->create(['email' => 'rina@example.com'] + $attributes);
    }

    public function test_guest_is_sent_to_login_and_returns_to_the_intended_page(): void
    {
        $this->user();

        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sesi berakhir setelah 30 menit');
        $this->post('/login', ['email' => 'rina@example.com', 'password' => 'password'])->assertRedirect('/'); // tujuan awal tamu
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('Dashboard');
    }

    public function test_successful_login_records_last_login_without_audit_log(): void
    {
        $user = $this->user();
        $this->assertNull($user->last_login_at);

        $this->post('/login', ['email' => 'rina@example.com', 'password' => 'password'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertSame(0, DB::table('audit_logs')->count()); // login tidak lagi dicatat di audit log
    }

    public function test_wrong_password_and_unknown_email_get_the_same_message(): void
    {
        $this->user();

        foreach ([['rina@example.com', 'salah'], ['tidak.ada@example.com', 'password']] as [$email, $password]) {
            $this->from('/login')->post('/login', compact('email', 'password'))
                ->assertRedirect('/login')->assertSessionHasErrors(['email' => 'Email atau password salah.']);
        }

        $this->assertGuest();
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_inactive_account_is_refused_with_the_generic_message(): void
    {
        $this->user(['is_active' => false]);

        $this->post('/login', ['email' => 'rina@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Email atau password salah.']);
        $this->assertGuest();
    }

    public function test_five_failed_attempts_lock_the_login_even_with_the_right_password(): void
    {
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'rina@example.com', 'password' => 'salah']);
        }

        $this->post('/login', ['email' => 'RINA@example.com', 'password' => 'password']) // huruf besar tidak mengakali batas
            ->assertInvalid(['email' => 'Terlalu banyak percobaan']);
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->post('/login', ['email' => 'rina@example.com', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->user());

        $this->get('/logout')->assertStatus(405); // logout hanya lewat POST ber-CSRF
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_user_deactivated_while_signed_in_is_logged_out_on_the_next_request(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    private function assertNotCacheable(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Pragma'));
    }

    public function test_authenticated_and_guest_responses_are_never_cached_by_the_browser(): void
    {
        $staff = $this->user();
        $project = Project::factory()->assignedTo($staff)->create(['created_by' => $staff->id]);

        $this->assertNotCacheable($this->get('/login')->assertOk());
        $this->assertNotCacheable($this->get('/')->assertRedirect('/login'));

        $this->actingAs($staff);
        $this->assertNotCacheable($this->get('/dashboard')->assertOk());
        $this->assertNotCacheable($this->get("/projects/{$project->id}")->assertOk());
        $this->assertNotCacheable($this->get('/projects/create')->assertOk());

        $this->assertNotCacheable($this->post('/logout')->assertRedirect('/login'));
        $this->assertNotCacheable($this->get('/')->assertRedirect('/login'));
    }

    public function test_signed_in_user_does_not_see_the_login_page_and_session_lasts_30_minutes(): void
    {
        $this->actingAs($this->user())->get('/login')->assertRedirect();
        $this->assertSame(30, config('session.lifetime'));
    }
}
