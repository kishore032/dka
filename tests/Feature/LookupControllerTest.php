<?php

namespace Tests\Feature;

use App\Models\PublicKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LookupController test suite.
 *
 * Uses Laravel's HTTP test client (getJson) against the real routes.
 * Keys are stored directly via PublicKey::create() — no crypto needed
 * since the lookup endpoints are read-only.
 *
 * The public_key column stores whatever base64 string the client originally
 * submitted, so tests use simple fake base64 values.
 */
class LookupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected string $email = 'alice@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dka.username'    => 'dka',
            'dka.terse'       => 'no-reply',
            'dka.domain'      => 'dka.example.com',
            'dka.mail_domain' => '*',
            'dka.version'     => 1,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function storeKey(
        string $email,
        string $selector,
        string $publicKey = 'dGVzdA==',
        array  $metadata  = [],
        int    $version   = 1
    ): PublicKey {
        return PublicKey::create([
            'email_id'   => $email,
            'selector'   => $selector,
            'public_key' => $publicKey,
            'metadata'   => json_encode($metadata),
            'version'    => $version,
        ]);
    }

    protected function lookupUrl(string $email, string $selector = null): string
    {
        $url = '/api/v1/lookup?email_address=' . urlencode($email);
        if ($selector !== null) {
            $url .= '&selector=' . urlencode($selector);
        }
        return $url;
    }

    // =========================================================================
    // GET /api/v1/lookup
    // =========================================================================

    #[Test]
    public function lookup_returns_400_when_email_is_missing(): void
    {
        $this->getJson('/api/v1/lookup')
            ->assertStatus(400)
            ->assertJsonFragment(['error' => 'invalid_request']);
    }

    #[Test]
    public function lookup_returns_404_when_email_has_no_keys(): void
    {
        $this->getJson($this->lookupUrl('nobody@example.com'))
            ->assertStatus(404)
            ->assertJsonFragment(['error' => 'not_found']);
    }

    #[Test]
    public function lookup_returns_default_selector_when_none_specified(): void
    {
        $this->storeKey($this->email, 'default');

        $this->getJson($this->lookupUrl($this->email))
            ->assertStatus(200)
            ->assertJsonFragment([
                'email_address' => $this->email,
                'selector'      => 'default',
            ]);
    }

    #[Test]
    public function lookup_returns_named_selector_when_specified(): void
    {
        $this->storeKey($this->email, 'signing');

        $this->getJson($this->lookupUrl($this->email, 'signing'))
            ->assertStatus(200)
            ->assertJsonFragment(['selector' => 'signing']);
    }

    #[Test]
    public function lookup_returns_public_key_as_stored(): void
    {
        $this->storeKey($this->email, 'default', 'bXlQdWJsaWNLZXk=');

        $data = $this->getJson($this->lookupUrl($this->email))
            ->assertStatus(200)
            ->json();

        $this->assertEquals('bXlQdWJsaWNLZXk=', $data['public_key']);
    }

    #[Test]
    public function lookup_does_not_include_algorithm_field(): void
    {
        $this->storeKey($this->email, 'default');

        $data = $this->getJson($this->lookupUrl($this->email))
            ->assertStatus(200)
            ->json();

        $this->assertArrayNotHasKey('algorithm', $data);
    }

    #[Test]
    public function lookup_returns_version_field(): void
    {
        $this->storeKey($this->email, 'default', 'dGVzdA==', [], 3);

        $data = $this->getJson($this->lookupUrl($this->email))
            ->assertStatus(200)
            ->json();

        $this->assertEquals(3, $data['version']);
    }

    #[Test]
    public function lookup_returns_metadata_as_decoded_object(): void
    {
        $this->storeKey($this->email, 'default', 'dGVzdA==', ['alg' => 'ed25519', 'use' => 'sig']);

        $meta = $this->getJson($this->lookupUrl($this->email))
            ->assertStatus(200)
            ->json('metadata');

        $this->assertEquals('ed25519', $meta['alg']);
        $this->assertEquals('sig', $meta['use']);
    }

    #[Test]
    public function lookup_returns_404_for_nonexistent_selector(): void
    {
        $this->storeKey($this->email, 'default');

        $this->getJson($this->lookupUrl($this->email, 'nosuchselector'))
            ->assertStatus(404)
            ->assertJsonFragment(['error' => 'not_found']);
    }

    #[Test]
    public function lookup_returns_403_for_email_outside_target_domain_in_dka_mode(): void
    {
        config(['dka.mail_domain' => 'allowed.com']);

        $this->getJson($this->lookupUrl('alice@other.com'))
            ->assertStatus(403)
            ->assertJsonFragment(['error' => 'domain_not_served']);
    }

    #[Test]
    public function lookup_allows_any_domain_in_rdka_mode(): void
    {
        config(['dka.mail_domain' => '*']);
        $this->storeKey('alice@any-domain.io', 'default');

        $this->getJson($this->lookupUrl('alice@any-domain.io'))
            ->assertStatus(200);
    }

    #[Test]
    public function lookup_includes_updated_at_timestamp(): void
    {
        $this->storeKey($this->email, 'default');

        $data = $this->getJson($this->lookupUrl($this->email))
            ->assertStatus(200)
            ->json();

        $this->assertNotNull($data['updated_at']);
    }

    #[Test]
    public function lookup_response_includes_cache_control_header(): void
    {
        $this->storeKey($this->email, 'default');

        $this->getJson($this->lookupUrl($this->email))
            ->assertStatus(200)
            ->assertHeader('Cache-Control');
    }

    // =========================================================================
    // GET /api/v1/apis
    // =========================================================================

    #[Test]
    public function apis_returns_exactly_three_endpoints(): void
    {
        $endpoints = $this->getJson('/api/v1/apis')
            ->assertStatus(200)
            ->json('endpoints');

        $this->assertCount(3, $endpoints);
    }

    #[Test]
    public function apis_returns_the_three_expected_paths(): void
    {
        $paths = array_column(
            $this->getJson('/api/v1/apis')->json('endpoints'),
            'path'
        );

        $this->assertContains('/api/v1/lookup',  $paths);
        $this->assertContains('/api/v1/version', $paths);
        $this->assertContains('/api/v1/apis',    $paths);
    }

    #[Test]
    public function apis_does_not_include_challenge_or_submit(): void
    {
        $paths = array_column(
            $this->getJson('/api/v1/apis')->json('endpoints'),
            'path'
        );

        $this->assertNotContains('/api/v1/selectors', $paths);
        $this->assertNotContains('/api/v1/challenge', $paths);
        $this->assertNotContains('/api/v1/submit',    $paths);
    }

    #[Test]
    public function apis_lists_all_endpoints_as_get_method(): void
    {
        $endpoints = $this->getJson('/api/v1/apis')->json('endpoints');

        foreach ($endpoints as $ep) {
            $this->assertEquals('GET', $ep['method'], "Expected GET for {$ep['path']}");
        }
    }
}
