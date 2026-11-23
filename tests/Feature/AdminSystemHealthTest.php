<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminDashboardController as Admin;
use App\Http\Middleware\RecordRequestMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Story B (PM 2026-11-16): penanda online, metrik request per menit, dan status sistem di dashboard admin.
class AdminSystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-03-10 12:30:00'); // UTC = 19:30 WIB
        $this->admin = User::factory()->role('SYSTEM_ADMIN', null)->create(['name' => 'Admin Uji']);
    }

    private function record(int $ms, int $status = 200): void
    {
        $request = Request::create('/x', 'GET', server: ['REQUEST_TIME_FLOAT' => microtime(true) - $ms / 1000]);
        (new RecordRequestMetric)->terminate($request, response('', $status));
    }

    private function metric(string $bucket, int $n, int $sum, int $max, int $errors = 0): void
    {
        DB::table('request_metrics')->insert(['bucket_at' => $bucket, 'request_count' => $n, 'duration_ms_sum' => $sum, 'duration_ms_max' => $max, 'error_5xx_count' => $errors]);
    }

    public function test_last_seen_is_throttled_to_once_per_minute_and_keeps_updated_at(): void
    {
        $updatedAt = $this->admin->fresh()->updated_at;
        $this->travel(1)->hour();
        $this->actingAs($this->admin)->get('/dashboard')->assertOk();
        $first = $this->admin->fresh()->last_seen_at;
        $this->assertEquals(now()->timestamp, $first->timestamp);

        $this->travel(30)->seconds();
        $this->get('/dashboard');
        $this->assertEquals($first->timestamp, $this->admin->fresh()->last_seen_at->timestamp);

        $this->travel(31)->seconds();
        $this->get('/dashboard');
        $this->assertEquals(now()->timestamp, $this->admin->fresh()->last_seen_at->timestamp);
        $this->assertEquals($updatedAt->timestamp, $this->admin->fresh()->updated_at->timestamp);
    }

    public function test_logout_clears_last_seen(): void
    {
        $this->actingAs($this->admin)->get('/dashboard');
        $this->assertNotNull($this->admin->fresh()->last_seen_at);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertNull($this->admin->fresh()->last_seen_at);
    }

    public function test_online_boundary_is_five_minutes_and_online_users_come_first(): void
    {
        User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Tepat Lima', 'last_seen_at' => now()->subMinutes(5)]);
        User::factory()->role('STAFF_PIC', 'CP')->create(['name' => 'Lewat Lima', 'last_seen_at' => now()->subMinutes(5)->subSecond(), 'last_login_at' => '2026-03-10 02:05:00']);
        User::factory()->role('MANAGER_GM', 'BM')->create(['name' => 'Aaa Belum Pernah']);

        $html = $this->actingAs($this->admin)->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['Admin Uji', 'Online', 'Tepat Lima', 'Online', 'Lewat Lima', 'Offline', 'terakhir aktif', 'Aaa Belum Pernah', 'Offline'])
            ->assertSee('10/03/2026 09:05') // last_login_at 02:05 UTC dalam WIB
            ->getContent();
        $this->assertStringContainsString('Belum pernah', $html);
    }

    public function test_metric_aggregates_requests_per_minute_and_counts_5xx(): void
    {
        $this->record(200);
        $this->record(500);
        $this->assertSame(1, DB::table('request_metrics')->count());
        $row = DB::table('request_metrics')->first();
        $this->assertSame('2026-03-10 12:30:00', $row->bucket_at);
        $this->assertSame(2, $row->request_count);
        $this->assertGreaterThanOrEqual(500, $row->duration_ms_max);
        $this->assertLessThan(5000, $row->duration_ms_max);
        $this->assertGreaterThanOrEqual(700, $row->duration_ms_sum);
        $this->assertSame(0, $row->error_5xx_count);

        $this->record(10, 500);
        $this->assertSame(1, DB::table('request_metrics')->value('error_5xx_count'));
        $this->assertSame(3, DB::table('request_metrics')->value('request_count'));
    }

    public function test_real_requests_are_recorded_by_the_web_middleware(): void
    {
        $this->get('/login')->assertOk();
        $this->assertSame(1, (int) DB::table('request_metrics')->sum('request_count'));
    }

    public function test_status_label_from_seeded_rows(): void
    {
        DB::table('request_metrics')->delete();
        $this->actingAs($this->admin)->get('/dashboard')->assertSee('Belum ada data');

        $cases = [
            'Stabil' => [100, 20000, 0],
            'Lambat' => [100, 100000, 0], // rata-rata 1000 ms
            'Lambat 5xx' => [100, 20000, 1],  // satu 5xx saja (1%)
            'Bermasalah' => [100, 20000, 5], // 5% error
            'Bermasalah lambat' => [100, 300000, 0], // rata-rata 3000 ms
        ];
        foreach ($cases as $expected => [$n, $sum, $errors]) {
            DB::table('request_metrics')->delete();
            $this->metric('2026-03-10 12:00:00', $n, $sum, 500, $errors);
            $this->actingAs($this->admin)->get('/dashboard')->assertSee(explode(' ', $expected)[0]);
            $this->assertSame(explode(' ', $expected)[0], Admin::health($n, $sum, $errors)['label']);
        }
        $this->assertSame('Stabil', Admin::health(100, 99900, 0)['label']); // 999 ms
    }

    public function test_old_metrics_are_pruned_and_hourly_chart_uses_wib_labels(): void
    {
        $this->metric('2026-03-03 12:29:00', 5, 500, 100); // lebih dari 7 hari
        $this->metric('2026-03-04 12:31:00', 5, 500, 100); // masih dalam 7 hari
        $this->metric('2026-03-10 12:00:00', 2, 400, 300);

        $this->actingAs($this->admin)->get('/dashboard')->assertOk()
            ->assertSee('Request per jam, 24 jam terakhir (WIB)')
            ->assertSeeInOrder(['<td>19:00</td><td>2</td><td>200</td><td>300</td><td>0</td>'], false);
        $this->assertSame(0, DB::table('request_metrics')->where('bucket_at', '2026-03-03 12:29:00')->count());
        $this->assertSame(1, DB::table('request_metrics')->where('bucket_at', '2026-03-04 12:31:00')->count());
    }

    public function test_admin_page_has_no_rupiah_and_other_dashboards_are_unchanged(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertDontSee('Rp')->assertSee('Kesehatan sistem');

        foreach (['STAFF_PIC', 'MANAGER_GM', 'DIREKSI'] as $role) {
            $this->actingAs(User::factory()->role($role, $role === 'DIREKSI' ? null : 'BM')->create())->get('/dashboard')->assertOk()
                ->assertDontSee('Kesehatan sistem')->assertDontSee('Aktivitas pengguna');
        }
    }
}
