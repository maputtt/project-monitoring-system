<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Support\Notify;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

// Story D: langganan web push. WebPush selalu di-mock; tes tidak pernah mengenkripsi atau mengirim sungguhan.
class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const FCM_A = 'https://fcm.googleapis.com/fcm/send/aaa';

    private const FCM_B = 'https://fcm.googleapis.com/fcm/send/bbb';

    private User $a;

    private User $b;

    private User $actor;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutDefer();
        config(['services.webpush.public_key' => 'pub', 'services.webpush.private_key' => 'priv']);
        $this->a = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->b = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->actor = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->a)->create(['created_by' => $this->a->id, 'status' => 'ACTIVE']);
    }

    private function payload(string $endpoint = self::FCM_A): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'k1', 'auth' => 'k2']];
    }

    private function sub(User $u, string $endpoint): void
    {
        DB::table('push_subscriptions')->insert(['user_id' => $u->id, 'endpoint' => $endpoint, 'public_key' => 'k1', 'auth_token' => 'k2']);
    }

    private function send(): void
    {
        Notify::send('decided', $this->project, $this->actor, 'Project BM-1 disetujui');
    }

    private function mails(): Collection
    {
        return collect(app('mail.manager')->mailer()->getSymfonyTransport()->messages());
    }

    private function report(int $status): MessageSentReport
    {
        return new MessageSentReport(new Request('POST', self::FCM_A), new Response($status), $status < 300);
    }

    private function flushing(array $reports): \Generator
    {
        yield from $reports;
    }

    public function test_subscribe_creates_row_and_remembers_endpoint(): void
    {
        $this->actingAs($this->a)->postJson('/push-subscriptions', $this->payload())->assertNoContent()->assertSessionHas('push_endpoint', self::FCM_A);

        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $this->a->id, 'endpoint' => self::FCM_A, 'public_key' => 'k1', 'auth_token' => 'k2']);
    }

    public function test_same_endpoint_from_another_user_reassigns_the_row(): void
    {
        $this->actingAs($this->a)->postJson('/push-subscriptions', $this->payload())->assertNoContent();
        $this->actingAs($this->b)->postJson('/push-subscriptions', $this->payload())->assertNoContent();

        $this->assertSame(1, DB::table('push_subscriptions')->count());
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $this->b->id, 'endpoint' => self::FCM_A]);
    }

    public function test_bad_endpoints_and_keys_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // lebih dari 10 permintaan dalam satu tes; throttle diuji terpisah
        $bad = ['http://fcm.googleapis.com/x', 'https://evil.example.com/x', 'https://fcm.googleapis.com.evil.com/x', 'https://fcm.googleapis.com@evil.com/x', 'https://127.0.0.1/x', 'https://fcm.googleapis.com:8443/x', 'https://evilpush.apple.com.evil.com/x', 'https://fcm.googleapis.com/'.str_repeat('a', 500)];
        foreach ($bad as $endpoint) {
            $this->actingAs($this->a)->postJson('/push-subscriptions', $this->payload($endpoint))->assertStatus(422);
        }
        $this->actingAs($this->a)->postJson('/push-subscriptions', ['endpoint' => self::FCM_A])->assertStatus(422);
        $this->assertSame(0, DB::table('push_subscriptions')->count());

        foreach (['https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/x', 'https://wns2-par02p.notify.windows.com/x'] as $ok) {
            $this->actingAs($this->a)->postJson('/push-subscriptions', $this->payload($ok))->assertNoContent();
        }
    }

    public function test_subscribe_is_throttled(): void
    {
        $this->actingAs($this->a);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/push-subscriptions', $this->payload())->assertNoContent();
        }
        $this->postJson('/push-subscriptions', $this->payload())->assertStatus(429);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->post('/push-subscriptions', $this->payload())->assertRedirect('/login');
        $this->assertSame(0, DB::table('push_subscriptions')->count());
    }

    public function test_logout_deletes_only_own_current_device_row(): void
    {
        $this->sub($this->a, self::FCM_A);
        $this->sub($this->a, 'https://fcm.googleapis.com/fcm/send/other-device-of-a');
        $this->sub($this->b, self::FCM_B);

        $this->actingAs($this->a)->withSession(['push_endpoint' => self::FCM_A])->post('/logout')->assertRedirect('/login');

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => self::FCM_A]);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => self::FCM_B, 'user_id' => $this->b->id]);
        $this->assertSame(2, DB::table('push_subscriptions')->count());
    }

    public function test_logout_does_not_delete_a_row_that_now_belongs_to_another_user(): void
    {
        $this->sub($this->b, self::FCM_A); // perangkat sudah dipindah ke B, sesi lama A masih menyimpan endpoint-nya

        $this->actingAs($this->a)->withSession(['push_endpoint' => self::FCM_A])->post('/logout');

        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => self::FCM_A, 'user_id' => $this->b->id]);
    }

    public function test_recipient_with_subscription_gets_push_not_mail_and_payload_is_safe(): void
    {
        $this->sub($this->a, self::FCM_A);
        $queued = [];
        $this->mock(WebPush::class, function (MockInterface $m) use (&$queued) {
            $m->shouldReceive('queueNotification')->once()->andReturnUsing(function (Subscription $s, string $payload) use (&$queued) {
                $queued[] = [$s->getEndpoint(), $payload];
            });
            $m->shouldReceive('flush')->once()->andReturn($this->flushing([$this->report(201)]));
        });

        $this->send();

        $this->assertCount(1, $queued);
        $this->assertSame(self::FCM_A, $queued[0][0]);
        $data = json_decode($queued[0][1], true);
        $this->assertSame(['title', 'url'], array_keys($data));
        $this->assertStringNotContainsString('Rp', $queued[0][1]);
        $this->assertSame('/projects/'.$this->project->id, $data['url']);
        $this->assertCount(0, $this->mails());
    }

    public function test_recipient_without_subscription_gets_mail_and_no_push_attempt(): void
    {
        $this->mock(WebPush::class)->shouldNotReceive('queueNotification');

        $this->send();

        $this->assertCount(1, $this->mails());
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_empty_vapid_keys_fall_back_to_mail(): void
    {
        config(['services.webpush.private_key' => '']);
        $this->sub($this->a, self::FCM_A);
        $this->mock(WebPush::class)->shouldNotReceive('queueNotification');

        $this->send();

        $this->assertCount(1, $this->mails());
    }

    public function test_expired_subscription_is_deleted(): void
    {
        $this->sub($this->a, self::FCM_A);
        $this->mock(WebPush::class, function (MockInterface $m) {
            $m->shouldReceive('queueNotification')->once();
            $m->shouldReceive('flush')->once()->andReturn($this->flushing([$this->report(410)]));
        });

        $this->send();

        $this->assertSame(0, DB::table('push_subscriptions')->count());
    }

    public function test_push_exception_does_not_break_the_action(): void
    {
        $this->sub($this->a, self::FCM_A);
        $this->mock(WebPush::class)->shouldReceive('queueNotification')->andThrow(new RuntimeException('boom'));

        $this->send();

        $this->assertSame(1, DB::table('notifications')->count()); // lonceng tetap tertulis, tidak ada exception ke pemanggil
        $this->assertSame(1, DB::table('push_subscriptions')->count());
    }

    public function test_reassigned_device_receives_no_push_for_the_previous_user_who_gets_mail(): void
    {
        $this->actingAs($this->a)->postJson('/push-subscriptions', $this->payload())->assertNoContent();
        $this->actingAs($this->b)->postJson('/push-subscriptions', $this->payload())->assertNoContent();
        $this->mock(WebPush::class)->shouldNotReceive('queueNotification'); // A tidak punya langganan lagi; project ini bukan milik B

        $this->send();

        $emails = $this->mails()->flatMap(fn ($m) => collect($m->getEnvelope()->getRecipients())->map->getAddress())->all();
        $this->assertSame([$this->a->email], $emails);
    }
}
