<?php

namespace Tests\Unit\Services\VkApi;

use Tests\TestCase;
use App\Exceptions\Vk\VkApiException;
use App\Exceptions\Vk\VkTransportException;
use App\Exceptions\Vk\VkUnexpectedResponseException;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkSdkAdapter;
use Mockery;
use VK\Actions\Wall;

class VkWallServiceTest extends TestCase
{
    private const OWNER_ID = '-12345678';
    private const TOKEN = 'test_token_vk_wall_service';

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    // ── helpers ──────────────────────────────────────────────

    private function makeServiceWithMockAdapter(): array
    {
        $mockWall = Mockery::mock(Wall::class);

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn(self::TOKEN);
        $mockAdapter->shouldReceive('wall')->andReturn($mockWall);

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(self::OWNER_ID);

        return [$service, $mockAdapter, $mockWall];
    }

    private function mockPost(int $id, string $text = 'Post text'): array
    {
        return [
            'id'       => $id,
            'text'     => $text,
            'date'     => time(),
            'likes'    => ['count' => 10],
            'reposts'  => ['count' => 2],
            'comments' => ['count' => 3],
        ];
    }

    // ── 1. items=[] returns empty array ──────────────────────

    public function test_empty_items_returns_empty_array(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $mockWall->shouldReceive('get')
            ->once()
            ->with(self::TOKEN, Mockery::any())
            ->andReturn(['items' => []]);

        $result = $service->getPosts();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ── 2. valid posts returned as array of objects ───────────

    public function test_valid_posts_returned_as_array_of_objects(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $mockWall->shouldReceive('get')
            ->once()
            ->with(self::TOKEN, Mockery::any())
            ->andReturn(['items' => [$this->mockPost(1), $this->mockPost(2)]]);

        $result = $service->getPosts(100, 0);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals('Post text', $result[0]->text);
        $this->assertEquals(10, $result[0]->likes->count);
    }

    // ── 3. Access denied → VkApiException, no retry ──────────

    public function test_access_denied_throws_vk_api_exception_without_retry(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $vkError = new \VK\Client\VKApiError([
            'error_code' => 15,
            'error_msg'  => 'Access denied',
        ]);
        $sdkException = new \VK\Exceptions\VKApiException(15, 'Access denied', $vkError);

        $mockWall->shouldReceive('get')
            ->once()
            ->with(self::TOKEN, Mockery::any())
            ->andThrow($sdkException);

        try {
            $service->getPosts();
            $this->fail('Expected VkApiException was not thrown');
        } catch (VkApiException $e) {
            $this->assertEquals(VkApiException::REASON_ACCESS_DENIED, $e->getReason());
            $this->assertEquals(15, $e->getApiCode());
        }
    }

    // ── 4. timeout once, then success → returns posts ────────

    public function test_retry_on_timeout_then_success(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $timeoutException = new \VK\Exceptions\VKClientException(
            new \GuzzleHttp\Exception\ConnectException(
                'cURL error 28: Operation timed out',
                new \GuzzleHttp\Psr7\Request('POST', '/')
            )
        );

        $mockWall->shouldReceive('get')
            ->once()
            ->with(self::TOKEN, Mockery::any())
            ->andThrow($timeoutException);

        $mockWall->shouldReceive('get')
            ->once()
            ->with(self::TOKEN, Mockery::any())
            ->andReturn(['items' => [$this->mockPost(1)]]);

        $result = $service->getPosts();

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->id);
    }

    // ── 5. two timeouts → throws VkTransportException ────────

    public function test_two_timeouts_throws_transport_exception(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $timeoutException = new \VK\Exceptions\VKClientException(
            new \GuzzleHttp\Exception\ConnectException(
                'cURL error 28: Operation timed out after 10000 ms',
                new \GuzzleHttp\Psr7\Request('POST', '/')
            )
        );

        // MAX_ATTEMPTS = 2 → 2 calls total (initial + 1 retry)
        $mockWall->shouldReceive('get')
            ->times(2)
            ->with(self::TOKEN, Mockery::any())
            ->andThrow($timeoutException);

        try {
            $service->getPosts();
            $this->fail('Expected VkTransportException was not thrown');
        } catch (VkTransportException $e) {
            $this->assertEquals(VkTransportException::REASON_TIMEOUT, $e->getReason());
        }
    }

    // ── 6. persistent API error → no retry ──────────────────

    public function test_persistent_api_error_no_retry(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $vkError = new \VK\Client\VKApiError([
            'error_code' => 5,
            'error_msg'  => 'Invalid token',
        ]);
        $sdkException = new \VK\Exceptions\VKApiException(5, 'Invalid token', $vkError);

        $mockWall->shouldReceive('get')
            ->once()  // only once — no retry for non-rate-limit API errors
            ->with(self::TOKEN, Mockery::any())
            ->andThrow($sdkException);

        try {
            $service->getPosts();
            $this->fail('Expected VkApiException was not thrown');
        } catch (VkApiException $e) {
            $this->assertEquals(VkApiException::REASON_INVALID_TOKEN, $e->getReason());
            $this->assertEquals(5, $e->getApiCode());
        }
    }

    // ── 7. rate limit → retry then success ──────────────────

    public function test_rate_limit_retry_then_success(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $vkError = new \VK\Client\VKApiError([
            'error_code' => 6,
            'error_msg'  => 'Too many requests per second',
        ]);
        $rateLimitException = new \VK\Exceptions\VKApiException(6, 'Too many requests per second', $vkError);

        $mockWall->shouldReceive('get')
            ->once()
            ->with(self::TOKEN, Mockery::any())
            ->andThrow($rateLimitException);

        $mockWall->shouldReceive('get')
            ->once()
            ->with(self::TOKEN, Mockery::any())
            ->andReturn(['items' => [$this->mockPost(42)]]);

        $result = $service->getPosts();

        $this->assertCount(1, $result);
        $this->assertEquals(42, $result[0]->id);
    }

    // ── 8. response without items → VkUnexpectedResponseException ──

    public function test_response_without_items_throws_unexpected_response(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $mockWall->shouldReceive('get')
            ->once()
            ->with(self::TOKEN, Mockery::any())
            ->andReturn(['unexpected_key' => 'value']);

        try {
            $service->getPosts();
            $this->fail('Expected VkUnexpectedResponseException was not thrown');
        } catch (VkUnexpectedResponseException $e) {
            $this->assertStringContainsString('missing or invalid items', $e->getMessage());
        }
    }

    // ── 9. token MUST NOT appear in exception message ───────

    public function test_token_absent_from_exception_message(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        // Token in raw VK API error_msg — known error code (15) → fixed user message
        $vkError = new \VK\Client\VKApiError([
            'error_code' => 15,
            'error_msg'  => 'https://api.vk.com/method/wall.get?access_token=' . self::TOKEN . '&owner_id=-1 failed',
        ]);
        $sdkException = new \VK\Exceptions\VKApiException(15, 'Access denied', $vkError);

        $mockWall->shouldReceive('get')
            ->once()
            ->with(self::TOKEN, Mockery::any())
            ->andThrow($sdkException);

        try {
            $service->getPosts();
            $this->fail('Expected VkApiException');
        } catch (VkApiException $e) {
            $msg = $e->getMessage();
            // Known error code → fixed user message, never contains raw API response
            $this->assertStringNotContainsString(self::TOKEN, $msg);
            $this->assertStringNotContainsString('access_token', $msg);
            $this->assertStringNotContainsString('api.vk.com', $msg);
        }
    }

    public function test_token_redacted_for_unknown_api_error(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        // Unknown error code (100) — fixed user message, raw error_msg not passed through
        $vkError = new \VK\Client\VKApiError([
            'error_code' => 100,
            'error_msg'  => 'https://api.vk.com/method/wall.get?access_token=' . self::TOKEN . '&owner_id=-1 failed',
        ]);
        $sdkException = new \VK\Exceptions\VKApiException(100, 'Unknown error', $vkError);

        $mockWall->shouldReceive('get')
            ->once()
            ->with(self::TOKEN, Mockery::any())
            ->andThrow($sdkException);

        try {
            $service->getPosts();
            $this->fail('Expected VkApiException');
        } catch (VkApiException $e) {
            $msg = $e->getMessage();
            // Fixed message — never contains raw SDK text, URL, or token
            $this->assertStringNotContainsString(self::TOKEN, $msg);
            $this->assertStringNotContainsString('api.vk.com', $msg);
            $this->assertStringContainsString('VK API error (code: 100)', $msg);
            $this->assertEquals(VkApiException::REASON_OTHER, $e->getReason());
            $this->assertEquals(100, $e->getApiCode());
        }
    }

    // ── 11. rate limit code 29 ──────────────────────────────

    public function test_rate_limit_code_29_retry_then_success(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $vkError = new \VK\Client\VKApiError([
            'error_code' => 29,
            'error_msg'  => 'Rate limit reached',
        ]);
        $sdkException = new \VK\Exceptions\VKApiException(29, 'Rate limit', $vkError);

        $mockWall->shouldReceive('get')->once()->andThrow($sdkException);
        $mockWall->shouldReceive('get')->once()->andReturn(['items' => [$this->mockPost(99)]]);

        $result = $service->getPosts();
        $this->assertCount(1, $result);
        $this->assertEquals(99, $result[0]->id);
    }

    // ── 12. items is null (invalid type) → VkUnexpectedResponseException ──

    public function test_null_items_throws_unexpected_response(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $mockWall->shouldReceive('get')->once()->andReturn(['items' => null]);

        try {
            $service->getPosts();
            $this->fail('Expected VkUnexpectedResponseException');
        } catch (VkUnexpectedResponseException $e) {
            $this->assertStringContainsString('missing or invalid items', $e->getMessage());
        }
    }

    // ── 13. two timeouts → message contains attempt count ───

    public function test_two_timeouts_message_contains_attempt_count(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        $timeoutException = new \VK\Exceptions\VKClientException(
            new \GuzzleHttp\Exception\ConnectException(
                'cURL error 28: timed out',
                new \GuzzleHttp\Psr7\Request('POST', '/')
            )
        );

        $mockWall->shouldReceive('get')->times(2)->andThrow($timeoutException);

        try {
            $service->getPosts();
            $this->fail('Expected VkTransportException');
        } catch (VkTransportException $e) {
            $this->assertStringContainsString('Выполнено попыток: 2', $e->getMessage());
        }
    }

    // ── 14. token MUST NOT appear in logged context ─────────

    public function test_token_absent_from_log_context(): void
    {
        [$service, $_, $mockWall] = $this->makeServiceWithMockAdapter();

        // Realistic error_msg from VK — may contain URL, access_token, client_secret
        $vkError = new \VK\Client\VKApiError([
            'error_code' => 5,
            'error_msg'  => 'https://api.vk.com/method/wall.get?access_token='
                . self::TOKEN . '&client_secret=secret123&owner_id=-1 failed',
        ]);
        $sdkException = new \VK\Exceptions\VKApiException(5, 'Invalid token', $vkError);

        $mockWall->shouldReceive('get')->once()->andThrow($sdkException);

        \Illuminate\Support\Facades\Log::shouldReceive('log')
            ->once()
            ->with('error', 'VK wall.get failed after retries', \Mockery::on(function ($context) {
                $serialized = json_encode($context ?: []);
                // All secrets must be absent from the entire serialized context
                return !str_contains($serialized, self::TOKEN)
                    && !str_contains($serialized, 'access_token')
                    && !str_contains($serialized, 'client_secret')
                    && !str_contains($serialized, 'secret123')
                    && !str_contains($serialized, 'api.vk.com');
            }));

        try {
            $service->getPosts();
            $this->fail('Expected VkApiException');
        } catch (VkApiException $e) {
            $this->assertEquals(VkApiException::REASON_INVALID_TOKEN, $e->getReason());
        }
    }

    // ── restored: getComments() ─────────────────────────────

    public function test_gets_comments(): void
    {
        $comments = [
            ['id' => 1, 'text' => 'Comment 1'],
            ['id' => 2, 'text' => 'Comment 2'],
        ];

        $mockAdapter = \Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn(self::TOKEN);
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['items' => $comments]);

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(self::OWNER_ID);
        $result = $service->getComments(123, 100, 0);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]->id);
    }

    // ── restored: getComment() ──────────────────────────────

    public function test_gets_single_comment(): void
    {
        $comment = ['id' => 123, 'text' => 'Single comment'];

        $mockAdapter = \Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn(self::TOKEN);
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$comment]);

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(self::OWNER_ID);
        $result = $service->getComment(123);

        $this->assertNotNull($result);
        $this->assertEquals(123, $result->id);
    }

    // ── restored: pinPost() ─────────────────────────────────

    public function test_pins_post(): void
    {
        $mockAdapter = \Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn(self::TOKEN);
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(1);

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(self::OWNER_ID);
        $result = $service->pinPost(123);

        $this->assertEquals(1, $result);
    }

    // ── restored: setOwner() ────────────────────────────────

    public function test_sets_owner_id(): void
    {
        $service = new VkWallService();
        $result = $service->setOwner('-12345678');

        $this->assertInstanceOf(VkWallService::class, $result);
    }
}
