<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->user = User::factory()->role('STAFF_PIC', 'BM')->create(['email' => 'rina@example.com']);
    }

    public function test_reset_link_is_sent_and_the_answer_does_not_reveal_whether_the_account_exists(): void
    {
        User::factory()->role('STAFF_PIC', 'BM')->create(['email' => 'nonaktif@example.com', 'is_active' => false]);
        $this->get('/login')->assertSee('Lupa password?');
        $this->get('/forgot-password')->assertOk();

        $answers = [];
        foreach (['rina@example.com', 'tidak.ada@example.com', 'nonaktif@example.com'] as $email) {
            $response = $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])->assertRedirect('/forgot-password');
            $answers[] = session('status');
            $response->assertSessionHasNoErrors();
        }

        $this->assertCount(1, array_unique($answers)); // jawabannya sama untuk ketiganya
        Notification::assertSentToTimes($this->user, ResetPassword::class, 1);
        Notification::assertCount(1); // email tak dikenal dan akun nonaktif tidak dikirimi apa pun
    }

    public function test_reset_email_is_in_indonesian_and_links_to_the_reset_page(): void
    {
        $this->post('/forgot-password', ['email' => 'rina@example.com']);

        Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification) {
            $mail = $notification->toMail($this->user);
            $this->assertSame('Buat password baru', $mail->actionText);
            $this->assertStringContainsString('/reset-password/'.$notification->token, $mail->actionUrl);
            $this->get($mail->actionUrl)->assertOk()->assertSee('rina@example.com');

            return true;
        });
    }

    public function test_valid_token_changes_the_password_once_and_writes_audit_log(): void
    {
        $token = Password::createToken($this->user);
        DB::table('sessions')->insert(['id' => 'sesi-lama', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);
        $form = ['token' => $token, 'email' => 'rina@example.com', 'password' => 'rahasia-baru', 'password_confirmation' => 'rahasia-baru'];

        $this->post('/reset-password', $form)->assertRedirect('/login')->assertSessionHas('status');

        $this->assertTrue(Hash::check('rahasia-baru', $this->user->fresh()->password_hash));
        $this->assertNotSame('rahasia-baru', $this->user->fresh()->password_hash); // tersimpan sebagai hash
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
        $log = DB::table('audit_logs')->sole();
        $this->assertSame(['UPDATE', 'USER', $this->user->id, 'Reset password'], [$log->action, $log->entity_type, $log->entity_id, $log->reason]);
        $this->assertStringNotContainsString('rahasia-baru', json_encode($log)); // password tidak pernah masuk audit

        // Password lama ditolak, password baru diterima.
        $this->post('/login', ['email' => 'rina@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'rina@example.com', 'password' => 'rahasia-baru'])->assertRedirect('/dashboard');
        $this->post('/logout');

        // Tautan hanya bisa dipakai sekali.
        $this->post('/reset-password', ['password' => 'coba-lagi-123', 'password_confirmation' => 'coba-lagi-123'] + $form)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('rahasia-baru', $this->user->fresh()->password_hash));
    }

    public function test_invalid_expired_or_mismatched_requests_are_refused(): void
    {
        $token = Password::createToken($this->user);
        $form = ['token' => $token, 'email' => 'rina@example.com', 'password' => 'rahasia-baru', 'password_confirmation' => 'rahasia-baru'];

        $this->post('/reset-password', ['token' => 'salah'] + $form)->assertSessionHasErrors('email');
        $this->post('/reset-password', ['password_confirmation' => 'beda-sekali'] + $form)->assertSessionHasErrors('password');
        $this->post('/reset-password', ['password' => 'pendek', 'password_confirmation' => 'pendek'] + $form)->assertSessionHasErrors('password');

        $this->travel(61)->minutes(); // tautan berlaku 60 menit
        $this->post('/reset-password', $form)->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password_hash));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_inactive_account_cannot_reset_even_with_a_valid_token(): void
    {
        $token = Password::createToken($this->user);
        $this->user->update(['is_active' => false]);

        $this->post('/reset-password', ['token' => $token, 'email' => 'rina@example.com', 'password' => 'rahasia-baru', 'password_confirmation' => 'rahasia-baru'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password_hash));
    }
}
