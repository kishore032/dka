<?php

namespace Tests\Feature;

use App\Mail\DkaMail;
use App\Models\PublicKey;
use App\Services\DkaService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DkaService test suite.
 *
 * Calls service methods directly (no HTTP routing).
 *
 * handleEmailChallenge — DKIM check, token issuance.
 * handleEmailRegister  — token validation, upsert, versioning.
 * handleEmailDelete    — token validation, deletion.
 */
class DkaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DkaService   $dka;
    protected TokenService $tokens;

    protected string $email       = 'alice@example.com';
    protected string $fromAddress = 'dka@dka.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'dka.username'  => 'dka',
            'dka.domain'    => 'dka.example.com',
            'dka.token_ttl' => 900,
        ]);

        $this->tokens = app(TokenService::class);
        $this->dka    = app(DkaService::class);
    }

    protected function tearDown(): void
    {
        Redis::connection('dka')->flushdb();
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function storeKey(string $emailId, string $selector, int $version = 1): PublicKey
    {
        return PublicKey::create([
            'email_id'   => $emailId,
            'selector'   => $selector,
            'public_key' => 'dGVzdA==',
            'metadata'   => '{}',
            'version'    => $version,
        ]);
    }

    protected function issueToken(string $emailId = null): string
    {
        return $this->tokens->create($emailId ?? $this->email, 'email');
    }

    protected function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'token'      => '',    // callers should set this
            'public_key' => 'dGVzdA==',
            'selector'   => 'default',
        ], $overrides);
    }

    // =========================================================================
    // handleEmailChallenge — DKIM enforcement
    // =========================================================================

    #[Test]
    public function challenge_sends_dkim_error_when_dkim_fails_verbose(): void
    {
        config(['dka.DKIM_required' => true]);
        $this->dka->handleEmailChallenge($this->email, 'fail', true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'DKIM'));
        $this->assertFalse($this->tokens->exists($this->email));
    }

    #[Test]
    public function challenge_sends_nothing_when_dkim_fails_terse(): void
    {
        config(['dka.DKIM_required' => true]);
        $this->dka->handleEmailChallenge($this->email, 'fail', false, $this->fromAddress);

        Mail::assertNothingSent();
    }

    #[Test]
    public function challenge_issues_token_when_dkim_passes(): void
    {
        $this->dka->handleEmailChallenge($this->email, 'pass', true, $this->fromAddress);

        $this->assertTrue($this->tokens->exists($this->email));
        $this->assertEquals('email', $this->tokens->get($this->email)['channel']);
    }

    #[Test]
    public function challenge_sends_token_email_when_dkim_passes(): void
    {
        $this->dka->handleEmailChallenge($this->email, 'Pass', true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Token'));
    }

    #[Test]
    public function challenge_always_sends_token_email_regardless_of_verbose(): void
    {
        // The token email is never suppressed — without it the sender cannot complete the flow.
        $this->dka->handleEmailChallenge($this->email, 'pass', false, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Token'));
        $this->assertTrue($this->tokens->exists($this->email));
    }

    #[Test]
    public function challenge_resends_same_token_when_token_already_exists(): void
    {
        $existing = $this->issueToken();

        $this->dka->handleEmailChallenge($this->email, 'pass', true, $this->fromAddress);

        $this->assertEquals($existing, $this->tokens->get($this->email)['token']);
        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Token'));
    }

    // =========================================================================
    // handleEmailRegister — token validation
    // =========================================================================

    #[Test]
    public function register_fails_when_token_value_does_not_match(): void
    {
        $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload(['token' => 'wrongvalue']), true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Failed'));
        $this->assertEquals(0, PublicKey::count());
        $this->assertTrue($this->tokens->exists($this->email)); // token survives
    }

    #[Test]
    public function register_fails_when_no_token_exists(): void
    {
        $this->dka->handleEmailRegister($this->email, $this->registerPayload(['token' => 'any']), true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Failed'));
        $this->assertEquals(0, PublicKey::count());
    }

    // =========================================================================
    // handleEmailRegister — key creation
    // =========================================================================

    #[Test]
    public function register_creates_key_with_version_1(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload(['token' => $token]), true, $this->fromAddress);

        $key = PublicKey::findKey($this->email, 'default');
        $this->assertNotNull($key);
        $this->assertEquals(1, $key->version);
    }

    #[Test]
    public function register_stores_email_id_selector_and_public_key(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload([
            'token' => $token, 'selector' => 'main', 'public_key' => 'cHViS2V5',
        ]), true, $this->fromAddress);

        $key = PublicKey::findKey($this->email, 'main');
        $this->assertEquals($this->email, $key->email_id);
        $this->assertEquals('cHViS2V5', $key->public_key);
    }

    #[Test]
    public function register_defaults_selector_to_default(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, ['token' => $token, 'public_key' => 'dGVzdA=='], true, $this->fromAddress);

        $this->assertNotNull(PublicKey::findKey($this->email, 'default'));
    }

    #[Test]
    public function register_consumes_token_on_success(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload(['token' => $token]), true, $this->fromAddress);

        $this->assertFalse($this->tokens->exists($this->email));
    }

    #[Test]
    public function register_sends_success_email_verbose(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload(['token' => $token]), true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Successful'));
    }

    #[Test]
    public function register_sends_nothing_on_success_terse(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload(['token' => $token]), false, $this->fromAddress);

        Mail::assertNothingSent();
        $this->assertEquals(1, PublicKey::count());
    }

    // =========================================================================
    // handleEmailRegister — upsert / versioning
    // =========================================================================

    #[Test]
    public function register_overwrites_existing_key_and_increments_version(): void
    {
        $this->storeKey($this->email, 'default', 1);
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload([
            'token' => $token, 'public_key' => 'bmV3S2V5',
        ]), true, $this->fromAddress);

        $key = PublicKey::findKey($this->email, 'default');
        $this->assertEquals('bmV3S2V5', $key->public_key);
        $this->assertEquals(2, $key->version);
    }

    #[Test]
    public function register_increments_version_across_multiple_updates(): void
    {
        $this->storeKey($this->email, 'default', 5);
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload(['token' => $token]), true, $this->fromAddress);

        $this->assertEquals(6, PublicKey::findKey($this->email, 'default')->version);
    }

    #[Test]
    public function register_upsert_does_not_create_a_second_row(): void
    {
        $this->storeKey($this->email, 'default');
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload(['token' => $token]), true, $this->fromAddress);

        $this->assertEquals(1, PublicKey::where('email_id', $this->email)->count());
    }

    // =========================================================================
    // handleEmailRegister — validation
    // =========================================================================

    #[Test]
    public function register_rejects_invalid_selector_format(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload([
            'token' => $token, 'selector' => 'my.key',
        ]), true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Failed'));
        $this->assertEquals(0, PublicKey::count());
        $this->assertTrue($this->tokens->exists($this->email)); // token survives on validation failure
    }

    #[Test]
    public function register_rejects_missing_public_key(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, ['token' => $token, 'selector' => 'default'], true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Failed'));
    }

    // =========================================================================
    // handleEmailRegister — metadata
    // =========================================================================

    #[Test]
    public function register_stores_metadata_object_as_json(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, $this->registerPayload([
            'token' => $token, 'metadata' => ['alg' => 'ed25519'],
        ]), true, $this->fromAddress);

        $this->assertEquals('ed25519', PublicKey::findKey($this->email, 'default')->getMetaArray()['alg']);
    }

    #[Test]
    public function register_defaults_metadata_to_empty_when_absent(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailRegister($this->email, ['token' => $token, 'public_key' => 'dGVzdA=='], true, $this->fromAddress);

        $this->assertEmpty(PublicKey::findKey($this->email, 'default')->getMetaArray());
    }

    // =========================================================================
    // handleEmailDelete — token validation
    // =========================================================================

    #[Test]
    public function delete_fails_when_token_value_does_not_match(): void
    {
        $this->storeKey($this->email, 'default');
        $this->issueToken();

        $this->dka->handleEmailDelete($this->email, 'default', ['token' => 'wrongvalue'], true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Failed'));
        $this->assertNotNull(PublicKey::findKey($this->email, 'default'));
        $this->assertTrue($this->tokens->exists($this->email));
    }

    #[Test]
    public function delete_fails_when_no_token_exists(): void
    {
        $this->storeKey($this->email, 'default');

        $this->dka->handleEmailDelete($this->email, 'default', ['token' => 'any'], true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Failed'));
        $this->assertNotNull(PublicKey::findKey($this->email, 'default'));
    }

    // =========================================================================
    // handleEmailDelete — deletion logic
    // =========================================================================

    #[Test]
    public function delete_removes_the_specified_key(): void
    {
        $this->storeKey($this->email, 'default');
        $token = $this->issueToken();

        $this->dka->handleEmailDelete($this->email, 'default', ['token' => $token], true, $this->fromAddress);

        $this->assertNull(PublicKey::findKey($this->email, 'default'));
    }

    #[Test]
    public function delete_consumes_token_on_success(): void
    {
        $this->storeKey($this->email, 'default');
        $token = $this->issueToken();

        $this->dka->handleEmailDelete($this->email, 'default', ['token' => $token], true, $this->fromAddress);

        $this->assertFalse($this->tokens->exists($this->email));
    }

    #[Test]
    public function delete_removes_named_selector_leaving_default_intact(): void
    {
        $this->storeKey($this->email, 'default');
        $this->storeKey($this->email, 'signing');
        $token = $this->issueToken();

        $this->dka->handleEmailDelete($this->email, 'signing', ['token' => $token], true, $this->fromAddress);

        $this->assertNull(PublicKey::findKey($this->email, 'signing'));
        $this->assertNotNull(PublicKey::findKey($this->email, 'default'));
    }

    #[Test]
    public function delete_sends_success_email_verbose(): void
    {
        $this->storeKey($this->email, 'default');
        $token = $this->issueToken();

        $this->dka->handleEmailDelete($this->email, 'default', ['token' => $token], true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Successful'));
    }

    #[Test]
    public function delete_sends_nothing_on_success_terse(): void
    {
        $this->storeKey($this->email, 'default');
        $token = $this->issueToken();

        $this->dka->handleEmailDelete($this->email, 'default', ['token' => $token], false, $this->fromAddress);

        Mail::assertNothingSent();
        $this->assertNull(PublicKey::findKey($this->email, 'default'));
    }

    #[Test]
    public function delete_sends_error_when_selector_not_found(): void
    {
        $token = $this->issueToken();

        $this->dka->handleEmailDelete($this->email, 'nosuchkey', ['token' => $token], true, $this->fromAddress);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Failed'));
    }

    #[Test]
    public function delete_does_not_affect_other_users_keys(): void
    {
        $this->storeKey('bob@example.com', 'default');
        $token = $this->issueToken();

        $this->dka->handleEmailDelete($this->email, 'default', ['token' => $token], true, $this->fromAddress);

        $this->assertNotNull(PublicKey::findKey('bob@example.com', 'default'));
    }
}
