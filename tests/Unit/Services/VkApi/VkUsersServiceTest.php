<?php

namespace Tests\Unit\Services\VkApi;

use App\Services\VkApi\VkSdkAdapter;
use App\Services\VkApi\VkUsersService;
use Mockery;
use Tests\TestCase;

class VkUsersServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_splits_250_ids_into_three_batches_and_merges_profiles(): void
    {
        $ids = range(1, 250);
        $chunkSizes = [];

        $service = $this->makeServiceWithUsersApi(function (array $params) use (&$chunkSizes) {
            $chunk = $params['user_ids'];
            $chunkSizes[] = count($chunk);

            return array_map(
                fn (int $id) => ['id' => $id, 'screen_name' => "user{$id}"],
                $chunk
            );
        });

        $profiles = $service->getByIds($ids);

        $this->assertSame([100, 100, 50], $chunkSizes);
        $this->assertCount(250, $profiles);
        $this->assertSame('user1', $profiles[1]['screen_name']);
        $this->assertSame('user250', $profiles[250]['screen_name']);
        foreach ($chunkSizes as $size) {
            $this->assertLessThanOrEqual(100, $size);
        }
    }

    public function test_deduplicates_input_ids_before_request(): void
    {
        $chunkSizes = [];
        $requestedIds = [];

        $service = $this->makeServiceWithUsersApi(function (array $params) use (&$chunkSizes, &$requestedIds) {
            $chunk = $params['user_ids'];
            $chunkSizes[] = count($chunk);
            $requestedIds = array_merge($requestedIds, $chunk);

            return array_map(
                fn (int $id) => ['id' => $id, 'screen_name' => "user{$id}"],
                $chunk
            );
        });

        $profiles = $service->getByIds([1, 1, 2]);

        $this->assertCount(1, $chunkSizes);
        $this->assertSame([1, 2], $requestedIds);
        $this->assertCount(2, $profiles);
        $this->assertArrayHasKey(1, $profiles);
        $this->assertArrayHasKey(2, $profiles);
    }

    public function test_empty_input_does_not_call_api(): void
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('execute')->never();
        $mockAdapter->shouldReceive('users')->never();

        $service = new VkUsersService();
        $service->setAdapter($mockAdapter);

        $this->assertSame([], $service->getByIds([]));
    }

    public function test_failed_batch_does_not_drop_successful_batches(): void
    {
        $call = 0;

        $service = $this->makeServiceWithUsersApi(function (array $params) use (&$call) {
            $call++;
            $chunk = $params['user_ids'];

            if ($call === 2) {
                throw new \Exception('VK API Error: temporary failure');
            }

            return array_map(
                fn (int $id) => ['id' => $id, 'screen_name' => "user{$id}"],
                $chunk
            );
        });

        $profiles = $service->getByIds(range(1, 250));

        $this->assertCount(150, $profiles);
        $this->assertArrayHasKey(1, $profiles);
        $this->assertArrayHasKey(100, $profiles);
        $this->assertArrayNotHasKey(101, $profiles);
        $this->assertArrayHasKey(201, $profiles);
        $this->assertArrayHasKey(250, $profiles);
    }

    public function test_each_request_user_ids_count_does_not_exceed_limit(): void
    {
        $chunkSizes = [];

        $service = $this->makeServiceWithUsersApi(function (array $params) use (&$chunkSizes) {
            $chunk = $params['user_ids'];
            $chunkSizes[] = count($chunk);

            return array_map(fn (int $id) => ['id' => $id], $chunk);
        });

        $service->getByIds(range(1, 301));

        $this->assertSame([100, 100, 100, 1], $chunkSizes);
        foreach ($chunkSizes as $size) {
            $this->assertLessThanOrEqual(100, $size);
        }
    }

    /**
     * @param callable(array): array $getHandler
     */
    private function makeServiceWithUsersApi(callable $getHandler): VkUsersService
    {
        $usersApi = Mockery::mock();
        $usersApi->shouldReceive('get')
            ->andReturnUsing(function ($token, array $params) use ($getHandler) {
                $this->assertSame('test_token', $token);
                $this->assertArrayHasKey('user_ids', $params);
                $this->assertArrayHasKey('fields', $params);

                return $getHandler($params);
            });

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('users')->andReturn($usersApi);
        $mockAdapter->shouldReceive('execute')
            ->andReturnUsing(function (callable $callback) {
                return $callback();
            });

        $service = new VkUsersService();
        $service->setAdapter($mockAdapter);

        return $service;
    }
}
