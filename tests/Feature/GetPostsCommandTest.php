<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Exceptions\Vk\VkApiException;
use App\Exceptions\Vk\VkTransportException;
use App\Services\VkApi\VkWallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use VK\Actions\Wall;

class GetPostsCommandTest extends TestCase
{
    use RefreshDatabase;
    private const OWNER = '-670335';

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function mockWallService(): VkWallService
    {
        $mock = Mockery::mock(VkWallService::class)->makePartial();
        $mock->shouldAllowMockingProtectedMethods();
        $this->app->instance(VkWallService::class, $mock);

        return $mock;
    }

    // ── 1. empty wall → "посты не найдены", code 0 ───────────

    public function test_empty_wall_shows_no_posts_and_exits_zero(): void
    {
        $mock = $this->mockWallService();
        $mock->shouldReceive('getPosts')->andReturn([]);

        $this->artisan('vk:posts-get', [
            '--owner' => self::OWNER,
            '--from'  => '2026-06-01',
            '--to'    => '2026-07-18',
        ])
            ->expectsOutputToContain('Посты за указанный период не найдены')
            ->assertExitCode(0);
    }

    // ── 2. timeout → ошибка таймаута, ненулевой код ─────────

    public function test_timeout_shows_error_and_exits_nonzero(): void
    {
        $mock = $this->mockWallService();
        $mock->shouldReceive('getPosts')
            ->andThrow(new VkTransportException(
                'Превышено время ожидания ответа.',
                VkTransportException::REASON_TIMEOUT
            ));

        $this->artisan('vk:posts-get', [
            '--owner' => self::OWNER,
            '--from'  => '2026-06-01',
            '--to'    => '2026-07-18',
        ])
            ->expectsOutputToContain('Ошибка при получении постов')
            ->assertExitCode(1);
    }

    // ── 3. access denied → ошибка доступа, ненулевой код ────

    public function test_access_denied_shows_error_and_exits_nonzero(): void
    {
        $mock = $this->mockWallService();
        $mock->shouldReceive('getPosts')
            ->andThrow(new VkApiException(
                'Доступ к стене запрещён.',
                VkApiException::REASON_ACCESS_DENIED,
                15
            ));

        $this->artisan('vk:posts-get', [
            '--owner' => self::OWNER,
            '--from'  => '2026-06-01',
            '--to'    => '2026-07-18',
        ])
            ->expectsOutputToContain('Ошибка при получении постов')
            ->assertExitCode(1);
    }

    // ── 4. timeout after --clear → не сообщает об успешной пустой выборке ──

    public function test_clear_with_timeout_does_not_report_empty_wall(): void
    {
        $mock = $this->mockWallService();
        $mock->shouldReceive('getPosts')
            ->andThrow(new VkTransportException(
                'Превышено время ожидания.',
                VkTransportException::REASON_TIMEOUT
            ));

        $this->artisan('vk:posts-get', [
            '--owner' => self::OWNER,
            '--from'  => '2026-06-01',
            '--to'    => '2026-07-18',
            '--db'    => true,
            '--clear' => true,
        ])
            ->assertExitCode(1)
            ->doesntExpectOutput('API вернул пустой ответ');
    }

    // ── 5. valid posts returned in selected format ──

    public function test_valid_posts_output_in_json_format(): void
    {
        $mockPost = (object) [
            'id'       => 37621,
            'text'     => 'Test post',
            'date'     => strtotime('2026-06-15'),
            'likes'    => (object) ['count' => 90],
            'reposts'  => (object) ['count' => 3],
            'comments' => (object) ['count' => 2],
        ];

        $mock = $this->mockWallService();
        // Single page with one post — pagination stops (count < 100)
        $mock->shouldReceive('getPosts')
            ->once()
            ->with(100, 0)
            ->andReturn([$mockPost]);

        $this->artisan('vk:posts-get', [
            '--owner'  => self::OWNER,
            '--from'   => '2026-06-01',
            '--to'     => '2026-07-18',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('37621');
    }

    // ── 6. --clear with timeout preserves existing data ─────

    public function test_clear_with_timeout_preserves_existing_data(): void
    {
        // Insert an existing post for the owner
        DB::table('vk_posts')->insert([
            'post_id'   => 99999,
            'owner_id'  => self::OWNER,
            'timestamp' => strtotime('2026-06-15'),
            'date'      => '2026-06-15 12:00:00',
            'text'      => 'Existing post',
            'likes'     => 10,
            'reposts'   => 2,
            'comments'  => 1,
            'url'       => 'https://vk.com/wall' . self::OWNER . '_99999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEquals(1, DB::table('vk_posts')->where('owner_id', self::OWNER)->count());

        // Mock getPosts to throw — --clear must NOT delete the existing post
        $mock = $this->mockWallService();
        $mock->shouldReceive('getPosts')
            ->andThrow(new VkTransportException(
                'Превышено время ожидания ответа от VK API.',
                VkTransportException::REASON_TIMEOUT
            ));

        $this->artisan('vk:posts-get', [
            '--owner' => self::OWNER,
            '--from'  => '2026-06-01',
            '--to'    => '2026-07-18',
            '--db'    => true,
            '--clear' => true,
        ])->assertExitCode(1);

        // The existing post MUST still be there
        $this->assertEquals(
            1,
            DB::table('vk_posts')->where('owner_id', self::OWNER)->count(),
            'Existing data must survive a failed --clear operation'
        );
    }

    // ── 7. retry after timeout at command level ─────────────

    public function test_success_after_retry_at_command_level(): void
    {
        $mockPost = (object) [
            'id'       => 37621,
            'text'     => 'Post after retry',
            'date'     => strtotime('2026-06-15'),
            'likes'    => (object) ['count' => 90],
            'reposts'  => (object) ['count' => 3],
            'comments' => (object) ['count' => 2],
        ];

        $timeoutException = new \VK\Exceptions\VKClientException(
            new \GuzzleHttp\Exception\ConnectException(
                'cURL error 28: timed out',
                new \GuzzleHttp\Psr7\Request('POST', '/')
            )
        );

        // Mock Wall action: first call throws, second returns posts
        $mockWallAction = Mockery::mock(Wall::class);
        $mockWallAction->shouldReceive('get')->once()->andThrow($timeoutException);
        $mockWallAction->shouldReceive('get')->once()->andReturn(['items' => [$mockPost]]);

        // Mock adapter
        $mockAdapter = Mockery::mock(\App\Services\VkApi\VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('wall')->andReturn($mockWallAction);

        // Real VkWallService with mock adapter — retry executes inside getPosts()
        $service = new \App\Services\VkApi\VkWallService();
        $service->setAdapter($mockAdapter);

        $this->app->instance(\App\Services\VkApi\VkWallService::class, $service);

        $this->artisan('vk:posts-get', [
            '--owner'  => self::OWNER,
            '--from'   => '2026-06-01',
            '--to'     => '2026-07-18',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('37621');
    }
}
