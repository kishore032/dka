<?php

namespace Tests\Feature;

use App\Services\TokenService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TokenService test suite.
 *
 * Exercises get(), exists(), create(), delete(), and ttl() against
 * the real 'dka' Redis connection. The database is not used.
 */
class TokenServiceTest extends TestCase
{
    protected TokenService $tokens;
    protected string $email = 'alice@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['dka.token_ttl' => 900]);
        $this->tokens = app(TokenService::class);
    }

    protected function tearDown(): void
    {
        Redis::connection('dka')->flushdb();
        parent::tearDown();
    }

    // =========================================================================
    // get()
    // =========================================================================

    #[Test]
    public function get_returns_null_when_no_token_exists(): void
    {
        $this->assertNull($this->tokens->get($this->email));
    }

    #[Test]
    public function get_returns_array_with_required_keys(): void
    {
        $this->tokens->create($this->email, 'email');
        $data = $this->tokens->get($this->email);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('token',      $data);
        $this->assertArrayHasKey('channel',    $data);
        $this->assertArrayHasKey('created_at', $data);
    }

    #[Test]
    public function get_returns_the_stored_channel(): void
    {
        $this->tokens->create($this->email, 'email');
        $this->assertEquals('email', $this->tokens->get($this->email)['channel']);
    }

    // =========================================================================
    // exists()
    // =========================================================================

    #[Test]
    public function exists_returns_false_when_no_token(): void
    {
        $this->assertFalse($this->tokens->exists($this->email));
    }

    #[Test]
    public function exists_returns_true_when_token_is_present(): void
    {
        $this->tokens->create($this->email, 'email');
        $this->assertTrue($this->tokens->exists($this->email));
    }

    #[Test]
    public function exists_is_scoped_to_the_given_email_id(): void
    {
        $this->tokens->create('bob@example.com', 'email');

        $this->assertFalse($this->tokens->exists('alice@example.com'));
        $this->assertTrue($this->tokens->exists('bob@example.com'));
    }

    // =========================================================================
    // create()
    // =========================================================================

    #[Test]
    public function create_returns_a_string_token(): void
    {
        $this->assertIsString($this->tokens->create($this->email, 'email'));
    }

    #[Test]
    public function create_returns_a_40_character_token(): void
    {
        $this->assertSame(40, strlen($this->tokens->create($this->email, 'email')));
    }

    #[Test]
    public function create_returned_token_matches_what_is_stored(): void
    {
        $returned = $this->tokens->create($this->email, 'email');
        $this->assertEquals($returned, $this->tokens->get($this->email)['token']);
    }

    #[Test]
    public function create_stores_the_correct_channel(): void
    {
        $this->tokens->create($this->email, 'email');
        $this->assertEquals('email', $this->tokens->get($this->email)['channel']);
    }

    #[Test]
    public function create_stores_a_valid_iso8601_created_at_timestamp(): void
    {
        $before = now()->subSecond();
        $this->tokens->create($this->email, 'email');
        $after = now()->addSecond();

        $createdAt = Carbon::parse($this->tokens->get($this->email)['created_at']);

        $this->assertTrue($createdAt->greaterThanOrEqualTo($before));
        $this->assertTrue($createdAt->lessThanOrEqualTo($after));
    }

    #[Test]
    public function create_overwrites_any_existing_token(): void
    {
        $first  = $this->tokens->create($this->email, 'email');
        $second = $this->tokens->create($this->email, 'email');

        $this->assertNotEquals($first, $second);
        $this->assertEquals($second, $this->tokens->get($this->email)['token']);
    }

    #[Test]
    public function create_produces_different_tokens_for_different_emails(): void
    {
        $t1 = $this->tokens->create('alice@example.com', 'email');
        $t2 = $this->tokens->create('bob@example.com',   'email');

        $this->assertNotEquals($t1, $t2);
    }

    #[Test]
    public function create_consecutive_calls_produce_unique_tokens(): void
    {
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $tokens[] = $this->tokens->create($this->email, 'email');
        }
        $this->assertSame(count($tokens), count(array_unique($tokens)));
    }

    #[Test]
    public function create_stores_data_under_the_correct_redis_key(): void
    {
        $returned = $this->tokens->create($this->email, 'email');

        $raw = Redis::connection('dka')->get('dka:token:' . $this->email);
        $this->assertNotNull($raw);

        $decoded = json_decode($raw, true);
        $this->assertEquals($returned, $decoded['token']);
    }

    #[Test]
    public function create_sets_ttl_matching_the_configured_token_ttl(): void
    {
        config(['dka.token_ttl' => 300]);
        $this->tokens->create($this->email, 'email');

        $ttl = $this->tokens->ttl($this->email);
        $this->assertGreaterThan(295, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);
    }

    // =========================================================================
    // delete()
    // =========================================================================

    #[Test]
    public function delete_removes_an_existing_token(): void
    {
        $this->tokens->create($this->email, 'email');
        $this->tokens->delete($this->email);

        $this->assertFalse($this->tokens->exists($this->email));
    }

    #[Test]
    public function delete_causes_get_to_return_null(): void
    {
        $this->tokens->create($this->email, 'email');
        $this->tokens->delete($this->email);

        $this->assertNull($this->tokens->get($this->email));
    }

    #[Test]
    public function delete_is_safe_when_no_token_exists(): void
    {
        $this->tokens->delete($this->email); // must not throw
        $this->assertNull($this->tokens->get($this->email));
    }

    #[Test]
    public function delete_only_removes_the_specified_email_token(): void
    {
        $this->tokens->create('alice@example.com', 'email');
        $this->tokens->create('bob@example.com',   'email');

        $this->tokens->delete('alice@example.com');

        $this->assertFalse($this->tokens->exists('alice@example.com'));
        $this->assertTrue($this->tokens->exists('bob@example.com'));
    }

    // =========================================================================
    // ttl()
    // =========================================================================

    #[Test]
    public function ttl_returns_null_when_no_token_exists(): void
    {
        $this->assertNull($this->tokens->ttl($this->email));
    }

    #[Test]
    public function ttl_returns_a_positive_integer_when_token_exists(): void
    {
        $this->tokens->create($this->email, 'email');

        $ttl = $this->tokens->ttl($this->email);
        $this->assertIsInt($ttl);
        $this->assertGreaterThan(0, $ttl);
    }

    #[Test]
    public function ttl_returns_null_after_the_token_is_deleted(): void
    {
        $this->tokens->create($this->email, 'email');
        $this->tokens->delete($this->email);

        $this->assertNull($this->tokens->ttl($this->email));
    }

    #[Test]
    public function ttl_honours_the_configured_token_ttl(): void
    {
        config(['dka.token_ttl' => 120]);
        $this->tokens->create($this->email, 'email');

        $ttl = $this->tokens->ttl($this->email);
        $this->assertGreaterThan(115, $ttl);
        $this->assertLessThanOrEqual(120, $ttl);
    }
}
