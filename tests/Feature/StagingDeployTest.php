<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StagingDeployTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('SEED_PASSWORD');
        parent::tearDown();
    }

    public function test_cloudflare_proxy_proto_and_ip_are_trusted_but_forwarded_host_is_ignored(): void
    {
        $request = null;
        Route::get('/_cf', function (Request $r) use (&$request) {
            $request = $r;

            return 'ok';
        })->middleware('web');

        foreach (['173.245.48.1', '2606:4700::1'] as $proxyIp) {
            $this->withServerVariables(['REMOTE_ADDR' => $proxyIp])
                ->get('/_cf', ['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example.test']);

            $this->assertTrue($request->isSecure());
            $this->assertSame('198.51.100.7', $request->ip());
            $this->assertNotSame('evil.example.test', $request->getHost());
        }
    }

    public function test_forwarded_headers_from_a_non_cloudflare_ip_are_ignored(): void
    {
        $request = null;
        Route::get('/_ip', function (Request $r) use (&$request) {
            $request = $r;

            return 'ok';
        })->middleware('web');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get('/_ip', ['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example.test']);

        $this->assertSame('203.0.113.9', $request->ip());
        $this->assertFalse($request->isSecure());
        $this->assertNotSame('evil.example.test', $request->getHost());
    }

    public function test_non_local_seed_requires_and_applies_a_strong_seed_password(): void
    {
        $this->app['env'] = 'staging';

        try {
            (new DatabaseSeeder)->run();
            $this->fail('Seeder harus menolak tanpa SEED_PASSWORD.');
        } catch (\RuntimeException) {
            $this->assertSame(0, User::count());
        }

        putenv('SEED_PASSWORD=Str0ng-Demo-Pass-1');
        (new DatabaseSeeder)->run();

        $this->assertSame(4, User::count());
        $this->assertTrue(Hash::check('Str0ng-Demo-Pass-1', User::first()->password_hash));
        $this->assertFalse(Hash::check('password', User::first()->password_hash));
    }
}
