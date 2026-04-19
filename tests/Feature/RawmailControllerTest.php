<?php

namespace Tests\Feature;

use App\Http\Controllers\RawmailController;
use App\Mail\DkaMail;
use App\Models\PublicKey;
use App\Models\Rawmail;
use App\Services\DkaService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RawmailController test suite.
 *
 * Every test calls $this->controller->receive($post, true) directly,
 * bypassing $_POST entirely.
 *
 * Two-step flow:
 *   Step 1 — no active token → DKIM check → issue token, send to sender.
 *   Step 2 — active email token → parse JSON body → dispatch based on fields.
 *
 * Step 2 routing is determined by the JSON payload:
 *   register body: {"token": "...", "public_key": "..."}
 *   delete body:   {"token": "...", "delete": true, "selector": "<optional>"}
 *   conflict:      {"token": "...", "public_key": "...", "delete": true} → rejected
 */
class RawmailControllerTest extends TestCase
{
    use RefreshDatabase;

    protected RawmailController $controller;
    protected TokenService      $tokens;

    protected string $mgSigningKey = 'test-signing-key';
    protected string $timestamp    = '1739884800';
    protected string $mgToken      = 'testtoken123';
    protected string $signature;

    protected string $senderEmail    = 'alice@example.com';
    protected string $recipientEmail = 'dka@dka.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'dka.username'       => 'dka',
            'dka.terse'          => 'no-reply',
            'dka.domain'         => 'dka.example.com',
            'dka.mail_domain'    => '*',
            'dka.token_ttl'      => 900,
            'dka.mg_signing_key' => $this->mgSigningKey,
        ]);

        $this->signature  = hash_hmac('sha256', $this->timestamp . $this->mgToken, $this->mgSigningKey);
        $this->tokens     = app(TokenService::class);
        $this->controller = new RawmailController(app(DkaService::class), $this->tokens);
    }

    protected function tearDown(): void
    {
        Redis::connection('dka')->flushdb();
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function makePost(array $overrides = []): array
    {
        return array_merge([
            'Message-Id'                  => '<test-' . uniqid() . '@example.com>',
            'From'                        => 'Alice <' . $this->senderEmail . '>',
            'recipient'                   => $this->recipientEmail,
            'subject'                     => '',
            'timestamp'                   => $this->timestamp,
            'token'                       => $this->mgToken,
            'signature'                   => $this->signature,
            'X-Mailgun-Dkim-Check-Result' => 'Pass',
            'X-Mailgun-Sflag'             => 'No',
        ], $overrides);
    }

    protected function storeKey(string $emailId, string $selector, string $publicKey = 'dGVzdA=='): PublicKey
    {
        return PublicKey::create([
            'email_id'   => $emailId,
            'selector'   => $selector,
            'public_key' => $publicKey,
            'metadata'   => '{}',
            'version'    => 1,
        ]);
    }

    /** Issue a token and return its value. */
    protected function issueToken(string $emailId = null): string
    {
        return $this->tokens->create($emailId ?? $this->senderEmail, 'email');
    }

    // =========================================================================
    // Basic validation
    // =========================================================================

    #[Test]
    public function it_returns_406_if_post_is_not_an_array(): void
    {
        $this->assertEquals(406, $this->controller->receive(null, true)->getStatusCode());
    }

    #[Test]
    public function it_returns_406_if_message_id_is_missing(): void
    {
        $post = $this->makePost();
        unset($post['Message-Id']);

        $this->assertEquals(406, $this->controller->receive($post, true)->getStatusCode());
    }

    #[Test]
    public function it_returns_200_for_duplicate_message_id(): void
    {
        $post = $this->makePost();
        Rawmail::create([
            'message_id' => $post['Message-Id'],
            'from_email' => $this->senderEmail,
            'to_email'   => $this->recipientEmail,
            'subject'    => '',
            'timestamp'  => $this->timestamp,
            'spam_flag'  => 'No',
            'dkim_check' => 'Pass',
        ]);

        $result = $this->controller->receive($post, true);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals(1, Rawmail::count());
    }

    #[Test]
    public function it_returns_401_for_invalid_mailgun_signature(): void
    {
        $post = $this->makePost(['signature' => 'invalidsignature']);
        $this->assertEquals(401, $this->controller->receive($post, true)->getStatusCode());
    }

    #[Test]
    public function it_returns_406_for_unparseable_from_address(): void
    {
        $this->assertEquals(406, $this->controller->receive($this->makePost(['From' => 'notanemail']), true)->getStatusCode());
    }

    #[Test]
    public function it_returns_403_for_domain_mismatch_in_domain_dka_mode(): void
    {
        config(['dka.mail_domain' => 'allowed.com']);
        $this->assertEquals(403, $this->controller->receive($this->makePost(['From' => 'x@other.com']), true)->getStatusCode());
    }

    #[Test]
    public function it_returns_406_for_unrecognised_recipient(): void
    {
        $this->assertEquals(406, $this->controller->receive($this->makePost(['recipient' => 'unknown@dka.example.com']), true)->getStatusCode());
    }

    #[Test]
    public function it_stores_rawmail_record_on_valid_request(): void
    {
        $this->controller->receive($this->makePost(['subject' => 'hello']), true);

        $this->assertEquals(1, Rawmail::count());
        $rawmail = Rawmail::first();
        $this->assertEquals($this->senderEmail, $rawmail->from_email);
        $this->assertEquals('Pass', $rawmail->dkim_check);
    }

    // =========================================================================
    // Step 1 — Challenge
    // =========================================================================

    #[Test]
    public function step1_issues_token_and_sends_email_when_dkim_passes(): void
    {
        $this->controller->receive($this->makePost(), true);

        $this->assertTrue($this->tokens->exists($this->senderEmail));
        $this->assertEquals('email', $this->tokens->get($this->senderEmail)['channel']);
        Mail::assertSent(DkaMail::class);
    }

    #[Test]
    public function step1_sends_dkim_error_email_and_no_token_when_dkim_fails_verbose(): void
    {
        config(['dka.DKIM_required' => true]);
        $this->controller->receive($this->makePost(['X-Mailgun-Dkim-Check-Result' => 'Fail']), true);

        $this->assertFalse($this->tokens->exists($this->senderEmail));
        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'DKIM'));
    }

    #[Test]
    public function step1_sends_nothing_when_dkim_fails_terse(): void
    {
        config(['dka.DKIM_required' => true]);
        $post = $this->makePost([
            'recipient'                   => 'no-reply@dka.example.com',
            'X-Mailgun-Dkim-Check-Result' => 'Fail',
        ]);

        $this->controller->receive($post, true);

        Mail::assertNothingSent();
        $this->assertFalse($this->tokens->exists($this->senderEmail));
    }

    #[Test]
    public function step1_resends_same_token_when_token_already_exists(): void
    {
        $existing = $this->issueToken();

        // Body has no JSON with actionable fields → falls through to Step 1 (resend)
        $this->controller->receive($this->makePost(['body-plain' => '']), true);

        // Original token unchanged, but re-sent
        $this->assertEquals($existing, $this->tokens->get($this->senderEmail)['token']);
        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Token'));
    }

    #[Test]
    public function step1_is_triggered_when_no_token_is_active(): void
    {
        $this->controller->receive($this->makePost(), true);

        $this->assertTrue($this->tokens->exists($this->senderEmail));
        $this->assertEquals(0, PublicKey::count());
    }

    #[Test]
    public function step1_is_triggered_even_for_register_subject_when_no_token_active(): void
    {
        $this->controller->receive($this->makePost(['subject' => 'register']), true);

        // No token in Redis means no step 2 was attempted
        $this->assertTrue($this->tokens->exists($this->senderEmail));
        $this->assertEquals(0, PublicKey::count());
    }

    // =========================================================================
    // Step 2 — Register
    // =========================================================================

    #[Test]
    public function step2_register_creates_key_and_consumes_token(): void
    {
        $token = $this->issueToken();
        $body  = json_encode(['token' => $token, 'public_key' => 'bmV3S2V5']);
        $post  = $this->makePost(['body-plain' => $body]);

        $this->controller->receive($post, true);

        $this->assertNotNull(PublicKey::findKey($this->senderEmail, 'default'));
        $this->assertFalse($this->tokens->exists($this->senderEmail));
    }

    #[Test]
    public function step2_register_sets_version_1_for_new_key(): void
    {
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => json_encode(['token' => $token, 'public_key' => 'dGVzdA=='])]);

        $this->controller->receive($post, true);

        $this->assertEquals(1, PublicKey::findKey($this->senderEmail, 'default')->version);
    }

    #[Test]
    public function step2_register_overwrites_key_and_increments_version(): void
    {
        $this->storeKey($this->senderEmail, 'default', 'b2xkS2V5');
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => json_encode([
            'token' => $token, 'public_key' => 'bmV3S2V5', 'selector' => 'default',
        ])]);

        $this->controller->receive($post, true);

        $key = PublicKey::findKey($this->senderEmail, 'default');
        $this->assertEquals('bmV3S2V5', $key->public_key);
        $this->assertEquals(2, $key->version);
    }

    #[Test]
    public function step2_register_uses_selector_from_json(): void
    {
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => json_encode([
            'token' => $token, 'public_key' => 'dGVzdA==', 'selector' => 'signing',
        ])]);

        $this->controller->receive($post, true);

        $this->assertNotNull(PublicKey::findKey($this->senderEmail, 'signing'));
    }

    #[Test]
    public function step2_register_sends_success_email_verbose(): void
    {
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => json_encode(['token' => $token, 'public_key' => 'dGVzdA=='])]);

        $this->controller->receive($post, true);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Successful'));
    }

    #[Test]
    public function step2_register_fails_with_wrong_token_and_keeps_token(): void
    {
        $this->issueToken();
        $post = $this->makePost(['body-plain' => json_encode([
            'token' => 'wrongtoken', 'public_key' => 'dGVzdA==',
        ])]);

        $this->controller->receive($post, true);

        $this->assertEquals(0, PublicKey::count());
        $this->assertTrue($this->tokens->exists($this->senderEmail)); // token survives
        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Failed'));
    }

    #[Test]
    public function no_json_in_body_with_active_token_falls_back_to_challenge_resend(): void
    {
        $existing = $this->issueToken();
        $post     = $this->makePost(['body-plain' => 'not json at all']);

        $this->controller->receive($post, true);

        // No actionable JSON fields → treated as Step 1 → token is re-sent
        $this->assertEquals(0, PublicKey::count());
        $this->assertEquals($existing, $this->tokens->get($this->senderEmail)['token']);
        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Token'));
    }

    #[Test]
    public function step2_register_parses_json_after_blank_lines(): void
    {
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => "\n\n" . json_encode(['token' => $token, 'public_key' => 'dGVzdA=='])]);

        $this->controller->receive($post, true);

        $this->assertEquals(1, PublicKey::count());
    }

    #[Test]
    public function step2_register_parses_json_after_quoted_reply_text(): void
    {
        // Simulates a reply email where the email client puts quoted text before the JSON.
        $token = $this->issueToken();
        $json  = json_encode(['token' => $token, 'public_key' => 'dGVzdA==']);
        $body  = "> On April 19, the DKA wrote:\n> Your token is ...\n\n" . $json;
        $post  = $this->makePost(['body-plain' => $body]);

        $this->controller->receive($post, true);

        $this->assertEquals(1, PublicKey::count());
    }

    #[Test]
    public function step2_register_parses_json_with_trailing_email_signature(): void
    {
        $token = $this->issueToken();
        $json  = json_encode(['token' => $token, 'public_key' => 'dGVzdA==', 'selector' => 'sig']);
        $post  = $this->makePost(['body-plain' => $json . "\n\n-- \nAlice"]);

        $this->controller->receive($post, true);

        $this->assertNotNull(PublicKey::findKey($this->senderEmail, 'sig'));
    }

    // =========================================================================
    // Step 2 — Delete
    // =========================================================================

    #[Test]
    public function step2_delete_removes_default_key_and_consumes_token(): void
    {
        $this->storeKey($this->senderEmail, 'default');
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => json_encode(['token' => $token, 'delete' => true])]);

        $this->controller->receive($post, true);

        $this->assertNull(PublicKey::findKey($this->senderEmail, 'default'));
        $this->assertFalse($this->tokens->exists($this->senderEmail));
    }

    #[Test]
    public function step2_delete_removes_named_selector(): void
    {
        $this->storeKey($this->senderEmail, 'signing');
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => json_encode(['token' => $token, 'delete' => true, 'selector' => 'signing'])]);

        $this->controller->receive($post, true);

        $this->assertNull(PublicKey::findKey($this->senderEmail, 'signing'));
    }

    #[Test]
    public function step2_delete_does_not_remove_other_selectors(): void
    {
        $this->storeKey($this->senderEmail, 'default');
        $this->storeKey($this->senderEmail, 'signing');
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => json_encode(['token' => $token, 'delete' => true, 'selector' => 'signing'])]);

        $this->controller->receive($post, true);

        $this->assertNotNull(PublicKey::findKey($this->senderEmail, 'default'));
    }

    #[Test]
    public function step2_delete_sends_success_email_verbose(): void
    {
        $this->storeKey($this->senderEmail, 'default');
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => json_encode(['token' => $token, 'delete' => true])]);

        $this->controller->receive($post, true);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Successful'));
    }

    #[Test]
    public function step2_delete_fails_with_wrong_token_and_keeps_token(): void
    {
        $this->storeKey($this->senderEmail, 'default');
        $this->issueToken();
        $post = $this->makePost(['body-plain' => json_encode(['token' => 'wrongtoken', 'delete' => true])]);

        $this->controller->receive($post, true);

        $this->assertNotNull(PublicKey::findKey($this->senderEmail, 'default'));
        $this->assertTrue($this->tokens->exists($this->senderEmail));
    }

    #[Test]
    public function step2_delete_sends_error_when_selector_not_found(): void
    {
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => json_encode(['token' => $token, 'delete' => true, 'selector' => 'nosuchkey'])]);

        $this->controller->receive($post, true);

        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Failed'));
    }

    // =========================================================================
    // Step 2 — Conflict (public_key + delete: true)
    // =========================================================================

    #[Test]
    public function step2_rejects_payload_with_both_public_key_and_delete_true(): void
    {
        $this->storeKey($this->senderEmail, 'default');
        $token = $this->issueToken();
        $post  = $this->makePost(['body-plain' => json_encode([
            'token'      => $token,
            'public_key' => 'dGVzdA==',
            'delete'     => true,
        ])]);

        $this->controller->receive($post, true);

        // Key must not be touched and token must survive
        $this->assertNotNull(PublicKey::findKey($this->senderEmail, 'default'));
        $this->assertTrue($this->tokens->exists($this->senderEmail));
        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Failed'));
    }

    // =========================================================================
    // Step 2 — no actionable fields falls back to Step 1
    // =========================================================================

    #[Test]
    public function json_with_only_token_field_falls_back_to_challenge_resend(): void
    {
        $existing = $this->issueToken();
        // JSON has a token field but neither public_key nor delete:true
        $post = $this->makePost(['body-plain' => json_encode(['token' => $existing])]);

        $this->controller->receive($post, true);

        // No actionable fields → Step 1 → resend token
        $this->assertEquals($existing, $this->tokens->get($this->senderEmail)['token']);
        Mail::assertSent(DkaMail::class, fn ($m) => str_contains($m->emailSubject, 'Token'));
    }

    // =========================================================================
    // Unknown / unrelated email still treated as challenge
    // =========================================================================

    #[Test]
    public function unknown_subject_falls_through_to_challenge(): void
    {
        $result = $this->controller->receive($this->makePost(['subject' => 'hello world']), true);

        $this->assertEquals(200, $result->getStatusCode());
        // No active token → treated as challenge
        $this->assertTrue($this->tokens->exists($this->senderEmail));
    }
}
