<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Exceptions\Vk\VkApiException;
use App\Services\VkApi\VkWallService;
use Mockery;

class WordCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_api_error_produces_nonzero_exit(): void
    {
        $mock = Mockery::mock(VkWallService::class)->makePartial();
        $mock->shouldReceive('getPosts')
            ->andThrow(new VkApiException(
                'Доступ к стене запрещён.',
                VkApiException::REASON_ACCESS_DENIED,
                15
            ));
        $mock->shouldReceive('setOwner')->andReturnSelf();

        $this->app->instance(VkWallService::class, $mock);

        $this->artisan('vk:word', [
            'word'    => 'test',
            '--owner' => '-12345678',
            '--from'  => '2026-06-01',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Ошибка при получении постов');
    }
}
