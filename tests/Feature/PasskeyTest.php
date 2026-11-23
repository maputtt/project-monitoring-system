<?php

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\User;
use App\Support\WebAuthn;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

// Face ID/sidik jari sebagai kunci aplikasi di HP: kunci P-256 nyata dibuat dan ditandatangani di PHP, tanpa browser.
class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-perangkat-uji';

    private OpenSSLAsymmetricKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withCredentials(); // tanpa ini cookie withCookie() tidak ikut di request JSON
        $this->key = $this->newKey();
    }

    /** Konfigurasi OpenSSL minimal: PHP di Windows/Laragon sering tanpa openssl.cnf default, sehingga openssl_pkey_new gagal. */
    private function newKey(array $opts = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']): OpenSSLAsymmetricKey
    {
        $cnf = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pms-min-openssl.cnf';
        file_exists($cnf) || file_put_contents($cnf, '[req]
distinguished_name = dn
[dn]
');

        return openssl_pkey_new($opts + ['config' => $cnf]);
    }

    private function spki(?OpenSSLAsymmetricKey $key = null): string
    {
        $pem = openssl_pkey_get_details($key ?? $this->key)['key'];

        return WebAuthn::b64(base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $pem)));
    }

    private function clientData(string $type, string $challenge, ?string $origin = null): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin ?? WebAuthn::origin()]);
    }

    private function authData(int $flags = 0x05, int $count = 0, string $credId = ''): string
    {
        return hash('sha256', WebAuthn::rpId(), true).chr($flags).pack('N', $count).($credId === '' ? '' : str_repeat("\0", 16).pack('n', strlen($credId)).$credId.'cose');
    }

    private function registration(string $challenge, array $over = []): array
    {
        $cred = random_bytes(32);
        $id = WebAuthn::b64($cred);

        return $over + [
            'id' => $id, 'rawId' => $id,
            'clientDataJSON' => WebAuthn::b64($this->clientData('webauthn.create', $challenge)),
            'authenticatorData' => WebAuthn::b64($this->authData(0x45, 0, $cred)),
            'publicKey' => $this->spki(), 'publicKeyAlgorithm' => -7, 'name' => 'iPhone Rina',
        ];
    }

    private function assertion(Passkey $pk, string $challenge, array $o = []): array
    {
        $client = $o['client'] ?? $this->clientData('webauthn.get', $challenge, $o['origin'] ?? null);
        $auth = $this->authData($o['flags'] ?? 0x05, $o['count'] ?? 0);
        openssl_sign($auth.hash('sha256', $client, true), $sig, $o['signWith'] ?? $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => $o['id'] ?? $pk->credential_id, 'rawId' => $pk->credential_id,
            'clientDataJSON' => WebAuthn::b64($client), 'authenticatorData' => WebAuthn::b64($auth),
            'signature' => WebAuthn::b64($sig), 'userHandle' => null,
        ];
    }

    /** HP yang sudah terdaftar dan login password: baris passkey + token perangkat yang masih berlaku. */
    private function device(?User $user = null, array $over = []): Passkey
    {
        $user ??= User::factory()->create();

        return $user->passkeys()->create($over + [
            'credential_id' => WebAuthn::b64(random_bytes(32)),
            'public_key' => WebAuthn::spkiToPem($this->spki(), -7),
            'algorithm' => -7, 'sign_count' => 0, 'name' => 'iPhone',
            'device_token_hash' => hash('sha256', self::TOKEN), 'device_expires_at' => now()->addDays(30),
        ]);
    }

    /** User yang baru login password (jendela 10 menit pendaftaran terbuka). */
    private function registrant(User $user): static
    {
        return $this->actingAs($user)->withSession(['password_login_at' => now()->timestamp]);
    }

    private function unlock(Passkey $pk, array $body, ?string $challenge = 'C', string $cookieToken = self::TOKEN, ?string $cookie = null): TestResponse
    {
        $t = $this->withCookie(WebAuthn::COOKIE, $cookie ?? $pk->id.'|'.$cookieToken)
            ->withSession(($challenge ? ['passkey_unlock' => ['v' => $challenge, 't' => now()->timestamp]] : []) + ['url.intended' => 'http://localhost/projects']);

        return $t->postJson('/passkey/unlock', $body);
    }

    private function audits(): int
    {
        return DB::table('audit_logs')->where('entity_type', 'USER')->count();
    }

    // --- Pendaftaran -------------------------------------------------------------------------

    public function test_options_shape_and_registration_issues_30_day_device_token_and_audit(): void
    {
        $user = User::factory()->create(['name' => 'Rina']);
        $this->registrant($user)->postJson('/passkeys/options')->assertOk()
            ->assertJsonPath('rp.id', WebAuthn::rpId())->assertJsonPath('authenticatorSelection.userVerification', 'required')
            ->assertJsonPath('attestation', 'none')->assertJsonPath('pubKeyCredParams.1.alg', -257)->assertJsonStructure(['challenge', 'user' => ['id', 'name', 'displayName']]);
        $challenge = session('passkey_register.v');
        $this->assertNotEmpty($challenge);

        $res = $this->registrant($user)->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])
            ->postJson('/passkeys', $this->registration('CH'))->assertOk()->assertJson(['ok' => true]);

        $pk = $user->passkeys()->first();
        $this->assertSame('iPhone Rina', $pk->name);
        $this->assertSame(hash('sha256', explode('|', $res->getCookie(WebAuthn::COOKIE)->getValue())[1]), $pk->device_token_hash);
        $this->assertTrue($pk->device_expires_at->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
        $this->assertSame($pk->id, session('passkey_id'));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'USER', 'entity_id' => $user->id, 'action' => 'UPDATE', 'reason' => 'Tambah passkey: iPhone Rina', 'user_id' => $user->id]);
    }

    public function test_device_cookie_is_secure_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class); // CSRF hanya dilewati otomatis di env testing
        $res = $this->registrant(User::factory()->create())->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])
            ->postJson('/passkeys', $this->registration('CH'))->assertOk();
        $this->assertTrue($res->getCookie(WebAuthn::COOKIE)->isSecure());
    }

    public function test_registration_refusals(): void
    {
        $user = User::factory()->create();
        $other = random_bytes(32);
        $cases = [
            'tantangan salah' => fn () => $this->registration('SALAH'),
            'origin salah' => fn () => ['clientDataJSON' => WebAuthn::b64($this->clientData('webauthn.create', 'CH', 'https://jahat.example'))] + $this->registration('CH'),
            'tanpa UV' => fn () => ['authenticatorData' => WebAuthn::b64($this->authData(0x41, 0, $other)), 'id' => WebAuthn::b64($other), 'rawId' => WebAuthn::b64($other)] + $this->registration('CH'),
            'id tidak cocok dengan authData' => fn () => ['id' => WebAuthn::b64($other), 'rawId' => WebAuthn::b64($other)] + $this->registration('CH'),
            'kunci bukan SPKI' => fn () => ['publicKey' => WebAuthn::b64('sampah')] + $this->registration('CH'),
            'algoritma tidak cocok kunci' => fn () => ['publicKeyAlgorithm' => -257] + $this->registration('CH'),
        ];
        foreach ($cases as $label => $body) {
            $this->registrant($user)->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])->postJson('/passkeys', $body())->assertStatus(422, $label);
        }
        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_registration_challenge_is_one_time_and_expires_after_five_minutes(): void
    {
        $user = User::factory()->create();
        $body = $this->registration('CH');
        $this->registrant($user)->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])->postJson('/passkeys', $body)->assertOk();
        $this->registrant($user)->postJson('/passkeys', $this->registration('CH'))->assertStatus(422); // sudah dipakai: tidak ada di session lagi

        $this->registrant($user)->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->subMinutes(6)->timestamp]])->postJson('/passkeys', $this->registration('CH'))->assertStatus(422);
        $this->assertSame(1, $user->passkeys()->count());
    }

    public function test_registration_rejects_duplicate_credential_and_guests(): void
    {
        $this->postJson('/passkeys', [])->assertUnauthorized();
        $pk = $this->device();
        $body = ['id' => $pk->credential_id, 'rawId' => $pk->credential_id, 'authenticatorData' => WebAuthn::b64($this->authData(0x45, 0, WebAuthn::unb64($pk->credential_id)))] + $this->registration('CH');
        $this->registrant(User::factory()->create())->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])->postJson('/passkeys', $body)->assertStatus(422);
    }

    // --- Buka kunci --------------------------------------------------------------------------

    public function test_unlock_logs_in_and_returns_intended_url_and_new_csrf(): void
    {
        $pk = $this->device(null, ['sign_count' => 5]);
        $expires = $pk->device_expires_at->copy();

        $this->unlock($pk, $this->assertion($pk, 'C', ['count' => 6]))->assertOk()
            ->assertJsonPath('redirect', 'http://localhost/projects')->assertJsonStructure(['csrf']);

        $this->assertAuthenticatedAs($pk->user);
        $this->assertSame($pk->id, session('passkey_id'));
        $fresh = $pk->fresh();
        $this->assertSame(6, $fresh->sign_count);
        $this->assertNotNull($fresh->last_used_at);
        $this->assertTrue($fresh->device_expires_at->equalTo($expires), 'token tidak diperpanjang');
        $this->assertSame(0, $this->audits(), 'buka kunci tidak diaudit');
    }

    public function test_unlock_works_with_rs256_and_zero_counters(): void
    {
        $rsa = $this->newKey(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $pk = $this->device(null, ['public_key' => WebAuthn::spkiToPem($this->spki($rsa), -257), 'algorithm' => -257]);

        $this->unlock($pk, $this->assertion($pk, 'C', ['signWith' => $rsa]))->assertOk();
        $this->assertAuthenticatedAs($pk->user);
    }

    public function test_unlock_refusals_leave_user_logged_out(): void
    {
        $pk = $this->device(null, ['sign_count' => 5]);
        $theirs = $this->device(User::factory()->create(), ['credential_id' => WebAuthn::b64(random_bytes(32))]);
        $good = fn (array $o = []) => $this->assertion($pk, 'C', $o + ['count' => 6]);
        $cases = [
            'tanpa cookie' => [fn () => $this->withSession(['passkey_unlock' => ['v' => 'C', 't' => now()->timestamp]])->postJson('/passkey/unlock', $good()), 401],
            'token salah' => [fn () => $this->unlock($pk, $good(), 'C', 'token-lain'), 401],
            'kredensial milik user lain' => [fn () => $this->unlock($pk, $this->assertion($theirs, 'C', ['count' => 6])), 422],
            'tantangan sudah dipakai' => [fn () => $this->unlock($pk, $good(), null), 422],
            'tantangan beda' => [fn () => $this->unlock($pk, $good(), 'LAIN'), 422],
            'origin salah' => [fn () => $this->unlock($pk, $good(['origin' => 'https://jahat.example'])), 422],
            'tanpa UV' => [fn () => $this->unlock($pk, $good(['flags' => 0x01])), 422],
            'counter mundur' => [fn () => $this->unlock($pk, $good(['count' => 5])), 422],
            'tanda tangan kunci lain' => [fn () => $this->unlock($pk, $good(['signWith' => $this->newKey()])), 422],
        ];
        foreach ($cases as $label => [$call, $status]) {
            $call()->assertStatus($status, $label);
            $this->assertGuest();
        }
    }

    public function test_unlock_refused_after_30_days_and_for_inactive_user(): void
    {
        $pk = $this->device();
        $this->travel(31)->days();
        $this->unlock($pk, $this->assertion($pk, 'C'))->assertUnauthorized();
        $this->travelBack();

        $pk->user->update(['is_active' => false]);
        $this->unlock($pk, $this->assertion($pk, 'C'))->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_unlock_options(): void
    {
        $pk = $this->device();

        $this->getJson('/passkey/unlock-options')->assertUnauthorized();
        $this->withCookie(WebAuthn::COOKIE, $pk->id.'|salah')->getJson('/passkey/unlock-options')->assertUnauthorized();
        $this->withCookie(WebAuthn::COOKIE, $pk->id.'|'.self::TOKEN)->getJson('/passkey/unlock-options')->assertOk()
            ->assertJsonPath('rpId', WebAuthn::rpId())->assertJsonPath('allowCredentials.0.id', $pk->credential_id)
            ->assertJsonPath('allowCredentials.0.type', 'public-key')->assertJsonPath('userVerification', 'required')->assertJsonStructure(['challenge', 'csrf']);
        $this->actingAs($pk->user)->getJson('/passkey/unlock-options')->assertNoContent();
    }

    // Sesi guest baru setelah kedaluwarsa 60 menit: token CSRF halaman basi, jadi options mengirim token segar yang dipakai untuk POST unlock.
    public function test_unlock_works_from_fresh_session_with_csrf_from_options(): void
    {
        $pk = $this->device();
        $cookie = $pk->id.'|'.self::TOKEN;

        $opts = $this->withCookie(WebAuthn::COOKIE, $cookie)->getJson('/passkey/unlock-options')->assertOk();
        $csrf = $opts->json('csrf');
        $this->assertNotEmpty($csrf);
        $this->assertSame($csrf, session('_token'));

        // Unlock tidak dikecualikan dari CSRF (middleware CSRF dilewati saat unit test, jadi 419 tidak bisa dipicu di sini); klien mengirim token segar dari options.
        $this->withCookie(WebAuthn::COOKIE, $cookie)->withHeader('X-CSRF-TOKEN', $csrf)
            ->postJson('/passkey/unlock', $this->assertion($pk, $opts->json('challenge')))->assertOk()->assertJsonStructure(['csrf', 'redirect']);
        $this->assertAuthenticatedAs($pk->user);
    }

    // QA: CSRF benar-benar menjaga unlock (di unit test middleware-nya dilewati, jadi dinyalakan paksa di sini).
    public function test_unlock_rejects_missing_or_stale_csrf_token_but_accepts_the_one_from_options(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $pk = $this->device();
        $cookie = $pk->id.'|'.self::TOKEN;
        $opts = $this->withCookie(WebAuthn::COOKIE, $cookie)->getJson('/passkey/unlock-options')->assertOk();
        $session = collect($opts->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $body = $this->assertion($pk, $opts->json('challenge'));
        $post = fn (array $h) => $this->withCookie(WebAuthn::COOKIE, $cookie)->withUnencryptedCookie($session->getName(), $session->getValue())->withHeaders($h)->postJson('/passkey/unlock', $body);

        $post([])->assertStatus(419);
        $post(['X-CSRF-TOKEN' => 'token-halaman-basi'])->assertStatus(419);
        $this->assertGuest();
        $post(['X-CSRF-TOKEN' => $opts->json('csrf')])->assertOk();
        $this->assertAuthenticatedAs($pk->user);
    }

    public function test_unlock_post_is_throttled_per_ip_without_device_cookie(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/passkey/unlock', [])->assertUnauthorized();
        }
        $this->postJson('/passkey/unlock', [])->assertStatus(429);
    }

    // Keputusan PM 2026-11-19: jatah 10/menit per perangkat (id passkey di cookie), bukan per IP kantor.
    public function test_unlock_post_budget_is_per_device(): void
    {
        $a = $this->device();
        $b = $this->device();
        for ($i = 0; $i < 10; $i++) {
            $this->withCookie(WebAuthn::COOKIE, $a->id.'|x')->postJson('/passkey/unlock', [])->assertUnauthorized();
        }
        $this->withCookie(WebAuthn::COOKIE, $a->id.'|x')->postJson('/passkey/unlock', [])->assertStatus(429);
        $this->withCookie(WebAuthn::COOKIE, $b->id.'|x')->postJson('/passkey/unlock', [])->assertUnauthorized(); // HP lain, IP sama: jatah utuh
        $this->postJson('/passkey/unlock', [])->assertUnauthorized(); // tanpa cookie: bucket IP terpisah
    }

    // Wi-Fi kantor: satu IP, banyak HP. Jatah cek kunci per perangkat (cookie), dan tidak berbagi dengan POST unlock.
    public function test_unlock_options_budget_is_per_device_not_per_ip(): void
    {
        $a = $this->device();
        $b = $this->device();
        for ($i = 0; $i < 20; $i++) {
            $this->withCookie(WebAuthn::COOKIE, $a->id.'|x')->getJson('/passkey/unlock-options')->assertUnauthorized();
        }
        $this->withCookie(WebAuthn::COOKIE, $a->id.'|x')->getJson('/passkey/unlock-options')->assertStatus(429);
        $this->withCookie(WebAuthn::COOKIE, $b->id.'|'.self::TOKEN)->getJson('/passkey/unlock-options')->assertOk();
        $this->postJson('/passkey/unlock', [])->assertUnprocessable(); // 422 = lolos throttle, ditolak validasi
    }

    // Sesi hasil buka kunci tidak boleh mendaftar: token 30 hari akan terbit ulang tanpa henti.
    public function test_session_born_from_unlock_cannot_register_a_passkey(): void
    {
        $pk = $this->device();
        $this->unlock($pk, $this->assertion($pk, 'C'))->assertOk();
        $this->assertAuthenticatedAs($pk->user);

        $this->postJson('/passkeys/options')->assertForbidden()->assertJsonPath('message', 'Masuk dengan password untuk mendaftarkan perangkat.');
        $this->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])->postJson('/passkeys', $this->registration('CH'))->assertForbidden();
        $this->assertSame(1, $pk->user->passkeys()->count());
    }

    // Keputusan PM 2026-11-19 (A): pendaftaran hanya 10 menit setelah login password di sesi ini.
    public function test_registration_window_is_ten_minutes_after_password_login(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertEqualsWithDelta(now()->timestamp, session('password_login_at'), 2);

        $this->travel(9)->minutes();
        $this->postJson('/passkeys/options')->assertOk();
        $this->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])->postJson('/passkeys', $this->registration('CH'))->assertOk();

        $this->travel(2)->minutes(); // total 11 menit
        $expired = 'Masuk ulang dengan password untuk mendaftarkan perangkat.';
        $this->postJson('/passkeys/options')->assertForbidden()->assertJsonPath('message', $expired);
        $this->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])->postJson('/passkeys', $this->registration('CH'))->assertForbidden()->assertJsonPath('message', $expired);
        $this->assertSame(1, $user->passkeys()->count());
    }

    // Tawaran Face ID hanya dirender selama pendaftaran memang diizinkan; /passkeys menawarkan keluar-masuk, bukan tombol yang pasti gagal.
    public function test_offers_render_only_while_registration_is_allowed(): void
    {
        $offer = 'aria-label="Aktifkan Face ID"';
        $btn = 'Aktifkan di ponsel ini';
        $relogin = 'Keluar dan masuk dengan password';
        $user = User::factory()->create();

        $this->registrant($user)->get('/dashboard')->assertSee($offer, false);
        $this->get('/passkeys')->assertSee($btn)->assertDontSee($relogin);

        $this->travel(11)->minutes();
        $this->get('/dashboard')->assertDontSee($offer, false);
        $this->get('/passkeys')->assertDontSee($btn)->assertSee($relogin);
    }

    public function test_session_without_password_login_marker_cannot_register(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/passkeys/options')->assertForbidden();
    }

    // Keputusan PM 2026-11-19 (B): hapus passkey (user atau admin) ikut menghapus langganan push HP itu; HP lain tidak tersentuh.
    public function test_removing_passkey_deletes_that_phones_push_subscription_only(): void
    {
        $fcm = fn (string $x) => 'https://fcm.googleapis.com/fcm/send/'.$x;
        $push = fn (string $e) => ['endpoint' => $e, 'keys' => ['p256dh' => 'k', 'auth' => 'a']];
        $user = User::factory()->create();

        // HP A: daftar passkey lalu langganan (session membawa passkey_id).
        $this->registrant($user)->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])->postJson('/passkeys', $this->registration('CH'))->assertOk();
        $a = $user->passkeys()->first();
        $this->postJson('/push-subscriptions', $push($fcm('A')))->assertNoContent();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $fcm('A'), 'passkey_id' => $a->id]);

        // Perangkat lain milik user yang sama tanpa passkey.
        $this->flushSession();
        $this->actingAs($user)->postJson('/push-subscriptions', $push($fcm('LAIN')))->assertNoContent();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $fcm('LAIN'), 'passkey_id' => null]);

        // Via cookie perangkat saja (session tanpa passkey_id): tertaut ke passkey yang sah.
        DB::table('push_subscriptions')->where('endpoint', $fcm('A'))->update(['passkey_id' => null]);
        $a->update(['device_token_hash' => hash('sha256', self::TOKEN)]);
        $this->flushSession();
        $this->actingAs($user)->withCookie(WebAuthn::COOKIE, $a->id.'|'.self::TOKEN)->postJson('/push-subscriptions', $push($fcm('A')))->assertNoContent();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $fcm('A'), 'passkey_id' => $a->id]);

        $this->liveSession($a)->delete('/passkeys/'.$a->id)->assertRedirect();
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $fcm('A')]);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $fcm('LAIN')]);

        // Jalur admin.
        $b = $this->device($user, ['credential_id' => WebAuthn::b64(random_bytes(32))]);
        $this->flushSession();
        $this->liveSession($b)->postJson('/push-subscriptions', $push($fcm('B')))->assertNoContent();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $fcm('B'), 'passkey_id' => $b->id]);
        $admin = User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create();
        $this->flushSession();
        $this->actingAs($admin)->delete("/master/users/{$user->id}/passkeys/{$b->id}")->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $fcm('B')]);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $fcm('LAIN')]);
    }

    // Endpoint yang berpindah user tidak boleh tetap tertaut ke passkey user lama, dan cookie perangkat user lain tidak dipakai.
    public function test_push_link_never_points_to_another_users_passkey(): void
    {
        $e = 'https://fcm.googleapis.com/fcm/send/SHARED';
        $body = ['endpoint' => $e, 'keys' => ['p256dh' => 'k', 'auth' => 'a']];
        $a = $this->device();
        $this->liveSession($a)->postJson('/push-subscriptions', $body)->assertNoContent();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $e, 'user_id' => $a->user_id, 'passkey_id' => $a->id]);

        // User B login password di HP yang sama, cookie perangkat milik A: langganan pindah ke B tanpa tautan passkey.
        $a->update(['device_token_hash' => hash('sha256', self::TOKEN)]);
        $this->flushSession();
        $b = User::factory()->create();
        $this->actingAs($b)->withCookie(WebAuthn::COOKIE, $a->id.'|'.self::TOKEN)->postJson('/push-subscriptions', $body)->assertNoContent();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $e, 'user_id' => $b->id, 'passkey_id' => null]);

        // linkPush tidak menyentuh baris milik user lain; hapus passkey A tidak menghapus langganan B.
        $req = Request::create('/');
        $req->setLaravelSession(tap(app('session.store'), fn ($st) => $st->put('push_endpoint', $e)));
        WebAuthn::linkPush($req, $a);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $e, 'user_id' => $b->id, 'passkey_id' => null]);
        $a->remove();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $e, 'user_id' => $b->id]);
    }

    public function test_registration_and_unlock_link_the_sessions_existing_subscription(): void
    {
        $user = User::factory()->create();
        $e = 'https://fcm.googleapis.com/fcm/send/EXIST';
        DB::table('push_subscriptions')->insert(['user_id' => $user->id, 'endpoint' => $e, 'public_key' => 'k', 'auth_token' => 'a', 'created_at' => now(), 'updated_at' => now()]);

        $this->registrant($user)->withSession(['push_endpoint' => $e, 'passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])->postJson('/passkeys', $this->registration('CH'))->assertOk();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $e, 'passkey_id' => $user->passkeys()->first()->id]);

        $pk = $this->device($user, ['credential_id' => WebAuthn::b64(random_bytes(32))]);
        DB::table('push_subscriptions')->where('endpoint', $e)->update(['passkey_id' => null]);
        auth()->forgetGuards(); // buang user actingAs: buka kunci hanya untuk yang belum login
        $this->flushSession();
        $this->withSession(['push_endpoint' => $e])->unlock($pk, $this->assertion($pk, 'C'))->assertOk();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $e, 'passkey_id' => $pk->id]);
    }

    // QA: larangan hanya untuk sesi hasil buka kunci; sesi hasil login password (dengan/tanpa cookie perangkat) tetap bisa mendaftar.
    public function test_session_born_from_password_login_can_still_register(): void
    {
        $pk = $this->device();
        $this->withCookie(WebAuthn::COOKIE, $pk->id.'|'.self::TOKEN)->post('/login', ['email' => $pk->user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertNull(session('passkey_unlocked'));
        $this->postJson('/passkeys/options')->assertOk();
        $this->postJson('/passkeys', $this->registration(session('passkey_register.v')))->assertOk();
        $this->assertSame(2, $pk->user->passkeys()->count());

        $fresh = User::factory()->create();
        $this->post('/logout');
        $this->post('/login', ['email' => $fresh->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->postJson('/passkeys/options')->assertOk();
    }

    // QA: kunci limiter = cookie terenkripsi; cookie palsu (tanpa APP_KEY) jatuh ke bucket IP dan tidak menghabiskan jatah perangkat orang lain.
    public function test_forged_device_cookie_cannot_drain_another_devices_options_budget(): void
    {
        $victim = $this->device();
        for ($i = 0; $i < 20; $i++) {
            $this->withUnencryptedCookie(WebAuthn::COOKIE, $victim->id.'|x')->getJson('/passkey/unlock-options')->assertUnauthorized();
        }
        $this->withUnencryptedCookie(WebAuthn::COOKIE, $victim->id.'|x')->getJson('/passkey/unlock-options')->assertStatus(429); // bucket IP penyerang
        $this->unencryptedCookies = []; // withUnencryptedCookie menempel di instance test dan menimpa withCookie
        $this->withCookie(WebAuthn::COOKIE, $victim->id.'|'.self::TOKEN)->getJson('/passkey/unlock-options')->assertOk(); // jatah korban utuh
    }

    // QA: pendaftaran ganda bersamaan yang lolos exists() berakhir 422 rapi dan tanpa baris/audit setengah jadi.
    public function test_concurrent_duplicate_registration_returns_422_not_500(): void
    {
        $user = User::factory()->create();
        $body = $this->registration('CH');
        Passkey::creating(fn (Passkey $m) => DB::table('passkeys')->insert(['user_id' => $m->user_id, 'credential_id' => $m->credential_id, 'public_key' => 'x', 'algorithm' => -7, 'name' => 'balapan', 'created_at' => now(), 'updated_at' => now()]));
        try {
            $this->registrant($user)->withSession(['passkey_register' => ['v' => 'CH', 't' => now()->timestamp]])->postJson('/passkeys', $body)->assertStatus(422)->assertJsonPath('message', 'Perangkat ini sudah terdaftar.');
        } finally {
            Passkey::flushEventListeners();
        }
        $this->assertSame(0, $user->passkeys()->count());
        $this->assertSame(0, $this->audits());
    }

    public function test_deactivating_user_clears_device_tokens_and_reactivation_does_not_revive_them(): void
    {
        $pk = $this->device();
        $admin = User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create();

        $this->actingAs($admin)->post("/master/users/{$pk->user_id}/deactivate")->assertRedirect();
        $this->assertNull($pk->fresh()->device_token_hash);
        $this->assertNull($pk->fresh()->device_expires_at);

        $this->actingAs($admin)->post("/master/users/{$pk->user_id}/activate")->assertRedirect();
        auth()->forgetGuards(); // buang user admin dari actingAs
        $this->flushSession();
        $this->withCookie(WebAuthn::COOKIE, $pk->id.'|'.self::TOKEN)->getJson('/passkey/unlock-options')->assertUnauthorized();
    }

    // --- Terkunci, pencabutan, logout --------------------------------------------------------

    public function test_locked_app_redirects_or_returns_401_for_json(): void
    {
        $pk = $this->device();
        $this->withCookie(WebAuthn::COOKIE, $pk->id.'|'.self::TOKEN)->get('/dashboard')->assertRedirect('/login');
        $this->withCookie(WebAuthn::COOKIE, $pk->id.'|'.self::TOKEN)->getJson('/dashboard')->assertUnauthorized(); // cookie saja tidak pernah mengautentikasi
    }

    private function liveSession(Passkey $pk): static
    {
        return $this->actingAs($pk->user)->withSession(['passkey_id' => $pk->id, 'passkey_hash' => $pk->device_token_hash]);
    }

    public function test_live_session_ends_when_passkey_removed_revoked_or_expired(): void
    {
        $pk = $this->device();
        $this->liveSession($pk)->get('/dashboard')->assertOk();

        $this->travel(31)->days();
        $this->liveSession($pk)->get('/dashboard')->assertRedirect('/login');
        $this->liveSession($pk)->getJson('/dashboard')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->travelBack();

        $session = $this->liveSession($pk); // memegang hash lama
        $pk->update(['device_token_hash' => hash('sha256', 'diganti')]);
        $session->get('/dashboard')->assertRedirect('/login');

        $pk->delete();
        $this->liveSession($pk)->get('/dashboard')->assertRedirect('/login');
    }

    public function test_user_removes_own_passkey_which_ends_session_and_blocks_unlock_and_is_audited(): void
    {
        $pk = $this->device();
        $this->liveSession($pk)->from('/passkeys')->delete('/passkeys/'.$pk->id)->assertRedirect('/passkeys')->assertSessionHas('status');

        $this->assertModelMissing($pk);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'USER', 'entity_id' => $pk->user_id, 'reason' => 'Hapus passkey: iPhone']);
        $this->liveSession($pk)->get('/dashboard')->assertRedirect('/login');
        $this->withCookie(WebAuthn::COOKIE, $pk->id.'|'.self::TOKEN)->getJson('/passkey/unlock-options')->assertUnauthorized();
    }

    public function test_user_cannot_remove_someone_elses_passkey(): void
    {
        $theirs = $this->device();
        $this->actingAs(User::factory()->create())->delete('/passkeys/'.$theirs->id)->assertNotFound();
        $this->assertModelExists($theirs);
    }

    public function test_admin_removes_any_users_passkey_others_cannot(): void
    {
        $pk = $this->device();
        $admin = User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create();

        $this->actingAs(User::factory()->role('MANAGER_GM', 'BM')->create())->delete("/master/users/{$pk->user_id}/passkeys/{$pk->id}")->assertForbidden();
        $this->actingAs($pk->user)->delete("/master/users/{$pk->user_id}/passkeys/{$pk->id}")->assertForbidden();
        $this->assertModelExists($pk);

        $other = User::factory()->create();
        $this->actingAs($admin)->delete("/master/users/{$other->id}/passkeys/{$pk->id}")->assertNotFound(); // bukan milik user di URL

        $this->actingAs($admin)->delete("/master/users/{$pk->user_id}/passkeys/{$pk->id}")->assertRedirect()->assertSessionHas('status');
        $this->assertModelMissing($pk);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $pk->user_id, 'reason' => 'Hapus passkey: iPhone', 'user_id' => $admin->id]);
        $this->liveSession($pk)->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_user_edit_receives_passkeys(): void
    {
        $pk = $this->device();
        $admin = User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create();
        $this->actingAs($admin)->get("/master/users/{$pk->user_id}/edit")->assertOk()->assertViewHas('passkeys', fn ($p) => $p->pluck('id')->all() === [$pk->id]);
    }

    public function test_logout_clears_device_token_and_password_login_reissues_it(): void
    {
        $pk = $this->device(null, ['name' => 'iPhone']);
        $user = $pk->user;

        $this->liveSession($pk)->post('/logout')->assertRedirect('/login');
        $this->assertNull($pk->fresh()->device_token_hash);
        $this->assertNull($pk->fresh()->device_expires_at);
        $this->withCookie(WebAuthn::COOKIE, $pk->id.'|'.self::TOKEN)->getJson('/passkey/unlock-options')->assertUnauthorized();

        // Login password di HP yang sama (cookie masih menamai baris ini): token baru 30 hari tanpa ceremony.
        $res = $this->withCookie(WebAuthn::COOKIE, $pk->id.'|'.self::TOKEN)->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $fresh = $pk->fresh();
        $this->assertNotNull($fresh->device_token_hash);
        $this->assertTrue($fresh->device_expires_at->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
        $this->assertSame($fresh->device_token_hash, hash('sha256', explode('|', $res->getCookie(WebAuthn::COOKIE)->getValue())[1]));
        $this->assertSame($pk->id, session('passkey_id'));
    }

    public function test_password_login_does_not_reissue_for_another_users_device_or_without_cookie(): void
    {
        $pk = $this->device(null, ['device_token_hash' => null, 'device_expires_at' => null]);
        $intruder = User::factory()->create();

        $this->withCookie(WebAuthn::COOKIE, $pk->id.'|x')->post('/login', ['email' => $intruder->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->post('/login', ['email' => $pk->user->email, 'password' => 'password'])->assertRedirect('/dashboard');

        $this->assertNull($pk->fresh()->device_token_hash);
        $this->assertNull(session('passkey_id'));
    }

    public function test_password_reset_keeps_passkeys(): void
    {
        $pk = $this->device();
        $token = Password::broker()->createToken($pk->user);

        $this->post('/reset-password', ['token' => $token, 'email' => $pk->user->email, 'password' => 'password-baru-1', 'password_confirmation' => 'password-baru-1'])->assertRedirect('/login');

        $this->assertModelExists($pk);
        $this->assertNotNull($pk->fresh()->device_token_hash);
    }
}
