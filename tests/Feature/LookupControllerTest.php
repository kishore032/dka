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
            'dka.username'      => 'dka',
            'dka.terse'         => 'no-reply',
            'dka.domain'        => 'dka.example.com',
            'dka.target_domain' => '*',
            'dka.version'       => 1,
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

    // =========================================================================
    // GET /api/v1/lookup
    // =========================================================================

    #[Test]
    public function lookup_returns_422_when_email_is_missing(): void
    {
        $this->getJson('/api/v1/lookup')
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'email parameter is required']);
    }

    #[Test]
    public function lookup_returns_404_when_email_has_no_keys(): void
    {
        $this->getJson('/api/v1/lookup?email=nobody@example.com')
            ->assertStatus(404);
    }

    #[Test]
    public function lookup_returns_default_selector_when_none_specified(): void
    {
        $this->storeKey($this->email, 'default');

        $this->getJson('/api/v1/lookup?email=' . $this->email)
            ->assertStatus(200)
            ->assertJsonFragment([
                'email_id' => $this->email,
                'selector' => 'default',
            ]);
    }

    #[Test]
    public function lookup_returns_named_selector_when_specified(): void
    {
        $this->storeKey($this->email, 'signing');

        $this->getJson('/api/v1/lookup?email=' . $this->email . '&selector=signing')
            ->assertStatus(200)
            ->assertJsonFragment(['selector' => 'signing']);
    }

    #[Test]
    public function lookup_returns_public_key_as_stored(): void
    {
        $this->storeKey($this->email, 'default', 'bXlQdWJsaWNLZXk=');

        $data = $this->getJson('/api/v1/lookup?email=' . $this->email)
            ->assertStatus(200)
            ->json();

        $this->assertEquals('bXlQdWJsaWNLZXk=', $data['public_key']);
    }

    #[Test]
    public function lookup_does_not_include_algorithm_field(): void
    {
        $this->storeKey($this->email, 'default');

        $data = $this->getJson('/api/v1/lookup?email=' . $this->email)
            ->assertStatus(200)
            ->json();

        $this->assertArrayNotHasKey('algorithm', $data);
    }

    #[Test]
    public function lookup_returns_version_field(): void
    {
        $this->storeKey($this->email, 'default', 'dGVzdA==', [], 3);

        $data = $this->getJson('/api/v1/lookup?email=' . $this->email)
            ->assertStatus(200)
            ->json();

        $this->assertEquals(3, $data['version']);
    }

    #[Test]
    public function lookup_returns_metadata_as_decoded_object(): void
    {
        $this->storeKey($this->email, 'default', 'dGVzdA==', ['alg' => 'ed25519', 'use' => 'sig']);

        $meta = $this->getJson('/api/v1/lookup?email=' . $this->email)
            ->assertStatus(200)
            ->json('metadata');

        $this->assertEquals('ed25519', $meta['alg']);
        $this->assertEquals('sig', $meta['use']);
    }

    #[Test]
    public function lookup_returns_404_for_nonexistent_selector(): void
    {
        $this->storeKey($this->email, 'default');

        $this->getJson('/api/v1/lookup?email=' . $this->email . '&selector=nosuchselector')
            ->assertStatus(404);
    }

    #[Test]
    public function lookup_returns_403_for_email_outside_target_domain_in_dka_mode(): void
    {
        config(['dka.target_domain' => 'allowed.com']);

        $this->getJson('/api/v1/lookup?email=alice@other.com')
            ->assertStatus(403)
            ->assertJsonFragment(['error' => 'This DKA does not serve that domain']);
    }

    #[Test]
    public function lookup_allows_any_domain_in_rdka_mode(): void
    {
        config(['dka.target_domain' => '*']);
        $this->storeKey('alice@any-domain.io', 'default');

        $this->getJson('/api/v1/lookup?email=alice@any-domain.io')
            ->assertStatus(200);
    }

    #[Test]
    public function lookup_includes_updated_at_timestamp(): void
    {
        $this->storeKey($this->email, 'default');

        $data = $this->getJson('/api/v1/lookup?email=' . $this->email)
            ->assertStatus(200)
            ->json();

        $this->assertNotNull($data['updated_at']);
    }

    // =========================================================================
    // GET /api/v1/selectors
    // =========================================================================

    #[Test]
    public function selectors_returns_422_when_email_is_missing(): void
    {
        $this->getJson('/api/v1/selectors')
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'email parameter is required']);
    }

    #[Test]
    public function selectors_returns_404_when_email_has_no_keys(): void
    {
        $this->getJson('/api/v1/selectors?email=nobody@example.com')
            ->assertStatus(404);
    }

    #[Test]
    public function selectors_lists_all_selectors_for_email(): void
    {
        $this->storeKey($this->email, 'default');
        $this->storeKey($this->email, 'signing');
        $this->storeKey($this->email, 'encrypt');

        $response = $this->getJson('/api/v1/selectors?email=' . $this->email)
            ->assertStatus(200)
            ->assertJsonFragment(['email_id' => $this->email]);

        $selectors = $response->json('selectors');
        $this->assertContains('default', $selectors);
        $this->assertContains('signing', $selectors);
        $this->assertContains('encrypt', $selectors);
        $this->assertCount(3, $selectors);
    }

    #[Test]
    public function selectors_does_not_mix_across_email_addresses(): void
    {
        $this->storeKey($this->email, 'default');
        $this->storeKey('bob@example.com', 'work');

        $selectors = $this->getJson('/api/v1/selectors?email=' . $this->email)
            ->json('selectors');

        $this->assertContains('default', $selectors);
        $this->assertNotContains('work', $selectors);
    }

    #[Test]
    public function selectors_returns_403_for_email_outside_target_domain_in_dka_mode(): void
    {
        config(['dka.target_domain' => 'allowed.com']);

        $this->getJson('/api/v1/selectors?email=alice@other.com')
            ->assertStatus(403);
    }

    // =========================================================================
    // GET /api/v1/version
    // =========================================================================

    #[Test]
    public function version_returns_rdka_mode_when_target_domain_is_wildcard(): void
    {
        config(['dka.target_domain' => '*', 'dka.version' => 1, 'dka.domain' => 'dka.example.com']);

        $this->getJson('/api/v1/version')
            ->assertStatus(200)
            ->assertExactJson([
                'dka_version' => 1,
                'domain'      => 'dka.example.com',
                'mode'        => 'rdka',
            ]);
    }

    #[Test]
    public function version_returns_dka_mode_when_target_domain_is_set(): void
    {
        config(['dka.target_domain' => 'example.com', 'dka.version' => 1, 'dka.domain' => 'dka.example.com']);

        $this->getJson('/api/v1/version')
            ->assertStatus(200)
            ->assertJsonFragment(['mode' => 'dka']);
    }

    // =========================================================================
    // GET /api/v1/apis
    // =========================================================================

    #[Test]
    public function apis_returns_exactly_four_endpoints(): void
    {
        $endpoints = $this->getJson('/api/v1/apis')
            ->assertStatus(200)
            ->json('endpoints');

        $this->assertCount(4, $endpoints);
    }

    #[Test]
    public function apis_returns_the_four_expected_paths(): void
    {
        $paths = array_column(
            $this->getJson('/api/v1/apis')->json('endpoints'),
            'path'
        );

        $this->assertContains('/api/v1/lookup',    $paths);
        $this->assertContains('/api/v1/selectors', $paths);
        $this->assertContains('/api/v1/version',   $paths);
        $this->assertContains('/api/v1/apis',      $paths);
    }

    #[Test]
    public function apis_does_not_include_challenge_or_submit(): void
    {
        $paths = array_column(
            $this->getJson('/api/v1/apis')->json('endpoints'),
            'path'
        );

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
