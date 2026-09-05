<?php

namespace Tests\Unit\Services\VkApi;

use App\Services\VkApi\VkApiGuard;
use App\Services\VkApi\VkRequestException;
use App\Services\VkApi\VkSdkAdapter;
use Tests\TestCase;
use VK\Client\VKApiError;
use VK\Exceptions\Api\VKApiFloodException;
use VK\Exceptions\Api\VKApiServerException;
use VK\Exceptions\Api\VKApiTooManyException;
use VK\Exceptions\VKClientException;

class VkSdkAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        VkApiGuard::reset();
    }

    protected function tearDown(): void
    {
        VkApiGuard::reset();
        parent::tearDown();
    }

    public function test_retries_too_many_requests_then_succeeds(): void
    {
        $calls = 0;
        $adapter = new VkSdkAdapter();

        $result = $adapter->execute(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw $this->tooMany();
            }

            return 'ok';
        }, 'getting friends for user 1', ['retry' => true, 'wait' => false]);

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
        $this->assertFalse(VkApiGuard::blocked());
    }

    public function test_retries_server_error_then_succeeds(): void
    {
        $calls = 0;
        $adapter = new VkSdkAdapter();

        $result = $adapter->execute(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw $this->serverError();
            }

            return 'ok';
        }, 'getting users profiles', ['retry' => true, 'wait' => false]);

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
        $this->assertFalse(VkApiGuard::blocked());
    }

    public function test_flood_stops_without_retry_and_blocks_guard(): void
    {
        $calls = 0;
        $adapter = new VkSdkAdapter();

        try {
            $adapter->execute(function () use (&$calls) {
                $calls++;
                throw $this->flood();
            }, 'getting friends for user 1', ['retry' => true, 'wait' => false]);
            $this->fail('Expected VkRequestException');
        } catch (VkRequestException $e) {
            $this->assertSame(VkRequestException::CATEGORY_FLOOD, $e->category);
            $this->assertTrue($e->stopsRun);
            $this->assertStringContainsString('getting friends for user 1', $e->getMessage());
        }

        $this->assertSame(1, $calls);
        $this->assertTrue(VkApiGuard::blocked());

        $laterCalls = 0;
        try {
            $adapter->execute(function () use (&$laterCalls) {
                $laterCalls++;

                return 'should-not-run';
            }, 'getting friends for user 2');
            $this->fail('Expected cooldown exception');
        } catch (VkRequestException $e) {
            $this->assertTrue($e->stopsRun);
            $this->assertStringContainsString('cooldown', $e->getMessage());
        }

        $this->assertSame(0, $laterCalls);
    }

    public function test_retries_transport_error_then_succeeds(): void
    {
        $calls = 0;
        $adapter = new VkSdkAdapter();

        $result = $adapter->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new VKClientException('Connection timed out');
            }

            return ['items' => []];
        }, 'getting users profiles', ['retry' => true, 'wait' => false]);

        $this->assertSame(['items' => []], $result);
        $this->assertSame(2, $calls);
    }

    public function test_does_not_retry_when_retry_option_is_false(): void
    {
        $calls = 0;
        $adapter = new VkSdkAdapter();

        try {
            $adapter->execute(function () use (&$calls) {
                $calls++;
                throw new VKClientException('Connection timed out');
            }, 'adding audio', ['wait' => false]);
            $this->fail('Expected VkRequestException');
        } catch (VkRequestException $e) {
            $this->assertSame(VkRequestException::CATEGORY_TRANSPORT, $e->category);
            $this->assertTrue($e->retryable);
        }

        $this->assertSame(1, $calls);
    }

    private function tooMany(): VKApiTooManyException
    {
        return new VKApiTooManyException(new VKApiError([
            'error_code' => 6,
            'error_msg' => 'Too many requests per second',
        ]));
    }

    private function flood(): VKApiFloodException
    {
        return new VKApiFloodException(new VKApiError([
            'error_code' => 9,
            'error_msg' => 'Flood control',
        ]));
    }

    private function serverError(): VKApiServerException
    {
        return new VKApiServerException(new VKApiError([
            'error_code' => 10,
            'error_msg' => 'Internal server error',
        ]));
    }
}
