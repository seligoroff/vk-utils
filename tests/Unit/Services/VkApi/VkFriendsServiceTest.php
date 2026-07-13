<?php

namespace Tests\Unit\Services\VkApi;

use App\Services\VkApi\VkFriendsService;
use App\Services\VkApi\VkSdkAdapter;
use Mockery;
use Tests\TestCase;

class VkFriendsServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_gets_friend_ids(): void
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['items' => [100, 200, 300]]);

        $service = new VkFriendsService();
        $service->setAdapter($mockAdapter);

        $result = $service->getFriendIds(12345);

        $this->assertSame([100, 200, 300], $result);
    }

    public function test_get_friend_ids_with_error_returns_friends_and_null_error(): void
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['items' => ['10', '20']]);

        $service = new VkFriendsService();
        $service->setAdapter($mockAdapter);

        $result = $service->getFriendIdsWithError(111);

        $this->assertSame([10, 20], $result['friends']);
        $this->assertNull($result['error']);
    }

    public function test_returns_empty_array_when_no_items(): void
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['count' => 0]);

        $service = new VkFriendsService();
        $service->setAdapter($mockAdapter);

        $result = $service->getFriendIdsWithError(12345);

        $this->assertSame([], $result['friends']);
        $this->assertNull($result['error']);
    }

    public function test_returns_null_and_error_on_unexpected_response_format(): void
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn((object) ['items' => [1, 2, 3]]);

        $service = new VkFriendsService();
        $service->setAdapter($mockAdapter);

        $result = $service->getFriendIdsWithError(12345);

        $this->assertNull($result['friends']);
        $this->assertSame('Unexpected VK response format', $result['error']);
    }

    public function test_returns_null_and_error_on_api_exception(): void
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andThrow(new \Exception('VK API Error: Access denied'));

        $service = new VkFriendsService();
        $service->setAdapter($mockAdapter);

        $result = $service->getFriendIdsWithError(12345);

        $this->assertNull($result['friends']);
        $this->assertSame('VK API Error: Access denied', $result['error']);
    }
}
