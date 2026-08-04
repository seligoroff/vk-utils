<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use App\Exceptions\Vk\VkApiException;
use App\Services\VkApi\VkWallService;
use Mockery;
use stdClass;

class GetPostsMockTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function makePost(array $data): object
    {
        $post = new stdClass();
        $post->id = $data['id'] ?? 1;
        $post->date = $data['date'] ?? time();
        $post->text = $data['text'] ?? '';

        $post->likes = new stdClass();
        $post->likes->count = $data['likes'] ?? 0;

        $post->reposts = new stdClass();
        $post->reposts->count = $data['reposts'] ?? 0;

        $post->comments = new stdClass();
        $post->comments->count = $data['comments'] ?? 0;

        return $post;
    }

    private function bindMockService(array $returnPosts): VkWallService
    {
        $mock = Mockery::mock(VkWallService::class)->makePartial();
        $mock->shouldReceive('getPosts')->andReturn($returnPosts);
        $this->app->instance(VkWallService::class, $mock);
        return $mock;
    }

    // ─────────────────────────────────────────────────────────

    public function test_gets_posts_with_mocked_api(): void
    {
        $posts = [
            $this->makePost(['id' => 123, 'date' => strtotime('2026-06-15'), 'text' => 'Post 1', 'likes' => 10, 'reposts' => 5]),
            $this->makePost(['id' => 124, 'date' => strtotime('2026-06-16'), 'text' => 'Post 2', 'likes' => 20, 'reposts' => 10]),
        ];

        $this->bindMockService($posts);

        $this->artisan('vk:posts-get', [
            '--owner'  => '-12345678',
            '--from'   => '2026-06-01',
            '--to'     => '2026-07-01',
            '--format' => 'json',
        ])->assertExitCode(0);
    }

    public function test_filters_posts_by_date_with_mocks(): void
    {
        $posts = [
            $this->makePost(['id' => 123, 'date' => strtotime('2026-06-15'), 'text' => 'In range']),
            $this->makePost(['id' => 125, 'date' => strtotime('2026-07-15'), 'text' => 'Out of range']),
        ];

        $this->bindMockService($posts);

        $this->artisan('vk:posts-get', [
            '--owner'  => '-12345678',
            '--from'   => '2026-06-01',
            '--to'     => '2026-07-01',
            '--format' => 'json',
        ])->assertExitCode(0);
    }

    public function test_filters_posts_with_text_only_with_mocks(): void
    {
        $posts = [
            $this->makePost(['id' => 123, 'text' => 'Has text', 'likes' => 10]),
            $this->makePost(['id' => 124, 'text' => '', 'likes' => 5]),
        ];

        $this->bindMockService($posts);

        $this->artisan('vk:posts-get', [
            '--owner'         => '-12345678',
            '--from'          => '2026-06-01',
            '--with-text-only' => true,
            '--format'        => 'json',
        ])->assertExitCode(0);
    }

    public function test_handles_empty_api_response(): void
    {
        $this->bindMockService([]);

        $this->artisan('vk:posts-get', [
            '--owner'  => '-12345678',
            '--from'   => '2026-06-01',
            '--format' => 'json',
        ])->assertExitCode(0);
    }

    public function test_handles_api_error(): void
    {
        $mock = Mockery::mock(VkWallService::class)->makePartial();
        $mock->shouldReceive('getPosts')
            ->andThrow(new VkApiException(
                'Доступ к стене запрещён. Проверьте owner ID и права токена.',
                VkApiException::REASON_ACCESS_DENIED,
                15
            ));
        $this->app->instance(VkWallService::class, $mock);

        // With the new contract, API errors produce nonzero exit code
        $this->artisan('vk:posts-get', [
            '--owner'  => '-12345678',
            '--from'   => '2026-06-01',
            '--format' => 'json',
        ])->assertExitCode(1);
    }

    public function test_handles_pagination_with_mocks(): void
    {
        // Page 1: 100 posts → command requests page 2
        $page1 = [];
        for ($i = 1; $i <= 100; $i++) {
            $page1[] = $this->makePost([
                'id'   => $i,
                'date' => strtotime('2026-06-15') + $i,
                'text' => "Post {$i}",
            ]);
        }

        // Page 2: 1 post → command stops pagination
        $page2 = [
            $this->makePost(['id' => 101, 'date' => strtotime('2026-06-15') + 101, 'text' => 'Post 101']),
        ];

        $mock = Mockery::mock(VkWallService::class)->makePartial();
        $mock->shouldReceive('getPosts')->once()->with(100, 0)->andReturn($page1);
        $mock->shouldReceive('getPosts')->once()->with(100, 100)->andReturn($page2);
        $this->app->instance(VkWallService::class, $mock);

        $this->artisan('vk:posts-get', [
            '--owner'  => '-12345678',
            '--from'   => '2026-06-01',
            '--format' => 'json',
        ])->assertExitCode(0);
    }
}
