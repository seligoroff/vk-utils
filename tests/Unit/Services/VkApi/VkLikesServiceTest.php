<?php

namespace Tests\Unit\Services\VkApi;

use App\Services\VkApi\VkLikesService;
use App\Services\VkApi\VkRequestException;
use App\Services\VkApi\VkSdkAdapter;
use Mockery;
use Tests\TestCase;

class VkLikesServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_gets_post_likers(): void
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['count' => 2, 'items' => ['10', '20']]);

        $service = new VkLikesService();
        $service->setAdapter($mockAdapter);

        $result = $service->getPostLikers('-1', 100);

        $this->assertSame(2, $result['total_count']);
        $this->assertSame([10, 20], $result['user_ids']);
    }

    public function test_flood_error_is_not_swallowed(): void
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andThrow(new VkRequestException(
                'Flood control',
                VkRequestException::CATEGORY_FLOOD,
                9,
                false,
                true
            ));

        $service = new VkLikesService();
        $service->setAdapter($mockAdapter);

        try {
            $service->getPostLikers('-670335', 37639);
            $this->fail('Expected VkRequestException');
        } catch (VkRequestException $e) {
            $this->assertSame(VkRequestException::CATEGORY_FLOOD, $e->category);
            $this->assertSame(9, $e->vkCode);
            $this->assertTrue($e->stopsRun);
        }
    }

    public function test_unexpected_format_stops_the_run(): void
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn('not-an-array');

        $service = new VkLikesService();
        $service->setAdapter($mockAdapter);

        try {
            $service->getPostLikers('-1', 1);
            $this->fail('Expected VkRequestException');
        } catch (VkRequestException $e) {
            $this->assertSame(VkRequestException::CATEGORY_UNEXPECTED_RESPONSE, $e->category);
            $this->assertTrue($e->stopsRun);
        }
    }
}
