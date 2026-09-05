<?php

namespace Tests\Feature;

use App\Services\VkApi\VkFriendsService;
use App\Services\VkApi\VkLikesService;
use App\Services\VkApi\VkRequestException;
use App\Services\VkApi\VkUsersService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class LikersCoreRunSafetyTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTables();
        DB::table('user_post_segments')->delete();
        DB::table('vk_posts')->delete();

        $this->outputPath = sys_get_temp_dir() . '/likers-core-run-safety-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->outputPath)) {
            unlink($this->outputPath);
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_flood_among_likers_fails_without_upsert_or_users_get(): void
    {
        $this->mockLikers([10, 20, 30]);

        $friends = $this->mock(VkFriendsService::class);
        $friends->shouldReceive('getFriendIdsWithError')->with(10)->once()->andReturn($this->okFriends([]));
        $friends->shouldReceive('getFriendIdsWithError')->with(20)->once()->andReturn($this->floodFriends());
        $friends->shouldReceive('getFriendIdsWithError')->with(30)->never();

        $this->mock(VkUsersService::class)->shouldReceive('getByIds')->never();

        $exitCode = $this->runCommand();
        $result = $this->readResult();

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', $result['run']['status']);
        $this->assertSame('flood', $result['run']['stopped_by']);
        $this->assertSame(1, $result['run']['error_counts']['flood']);
        $this->assertSame(0, $result['profiles']['requested']);
        $this->assertNull($result['demographics']);
        $this->assertSame(0, DB::table('user_post_segments')->count());
        $this->assertStringContainsString('Сегменты не сохранены: Flood control (код 9)', Artisan::output());
    }

    public function test_privacy_only_completes_with_hidden_segments(): void
    {
        $this->mockLikers([11, 12]);

        $friends = $this->mock(VkFriendsService::class);
        $friends->shouldReceive('getFriendIdsWithError')->times(2)->andReturn($this->privacyFriends());

        $this->mock(VkUsersService::class)
            ->shouldReceive('getByIds')
            ->once()
            ->andReturn([]);

        $exitCode = $this->runCommand();
        $result = $this->readResult();

        $this->assertSame(0, $exitCode);
        $this->assertSame('complete', $result['run']['status']);
        $this->assertNull($result['run']['stopped_by']);
        $this->assertSame(2, $result['run']['error_counts']['privacy']);
        $this->assertSame(
            ['hidden', 'hidden'],
            DB::table('user_post_segments')->orderBy('user_id')->pluck('segment')->all()
        );
        $this->assertFalse($result['users'][0]['friends_data_available']);
        $this->assertSame('privacy', $result['users'][0]['error_category']);
    }

    public function test_complete_run_upserts_core_hidden_and_open_without_technical_hidden(): void
    {
        $this->mockLikers([10, 20, 30]);

        $friends = $this->mock(VkFriendsService::class);
        $friends->shouldReceive('getFriendIdsWithError')->with(10)->once()->andReturn($this->okFriends([20]));
        $friends->shouldReceive('getFriendIdsWithError')->with(20)->once()->andReturn($this->privacyFriends());
        $friends->shouldReceive('getFriendIdsWithError')->with(30)->once()->andReturn($this->okFriends([]));

        $this->mock(VkUsersService::class)
            ->shouldReceive('getByIds')
            ->once()
            ->andReturn([
                10 => ['id' => 10, 'screen_name' => 'core_user'],
                20 => ['id' => 20, 'screen_name' => 'hidden_user'],
                30 => ['id' => 30, 'screen_name' => 'open_user'],
            ]);

        $exitCode = $this->runCommand();
        $result = $this->readResult();

        $this->assertSame(0, $exitCode);
        $this->assertSame('complete', $result['run']['status']);

        $byUser = [];
        foreach (DB::table('user_post_segments')->get() as $row) {
            $byUser[(int) $row->user_id] = $row->segment;
        }

        $this->assertSame([
            10 => 'core',
            20 => 'hidden',
            30 => 'open',
        ], $byUser);
        $this->assertSame(1, $result['summary']['core_users_count']);
    }

    public function test_alternating_technical_errors_fail_without_upsert(): void
    {
        $this->mockLikers([10, 20, 30, 40, 50]);

        $responses = [
            10 => $this->transportFriends(),
            20 => $this->okFriends([]),
            30 => $this->transportFriends(),
            40 => $this->okFriends([]),
            50 => $this->transportFriends(),
        ];

        $friends = $this->mock(VkFriendsService::class);
        $friends->shouldReceive('getFriendIdsWithError')
            ->times(5)
            ->andReturnUsing(function (...$args) use ($responses) {
                return $responses[(int) $args[0]];
            });

        $this->mock(VkUsersService::class)->shouldReceive('getByIds')->never();

        $exitCode = $this->runCommand();
        $result = $this->readResult();

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', $result['run']['status']);
        $this->assertSame('transport', $result['run']['stopped_by']);
        $this->assertSame(3, $result['run']['error_counts']['transport']);
        $this->assertSame(0, DB::table('user_post_segments')->count());
        $this->assertSame([20, 40], array_map(fn(array $r) => $r['user_id'], $result['users']));
        $this->assertSame(0, $result['profiles']['requested']);
    }

    public function test_single_technical_skip_does_not_mix_with_previous_segments(): void
    {
        DB::table('user_post_segments')->insert([
            ['user_id' => 10, 'owner_id' => '-670335', 'post_id' => 37639, 'segment' => 'open', 'friends_in_likers_count' => 1],
            ['user_id' => 20, 'owner_id' => '-670335', 'post_id' => 37639, 'segment' => 'core', 'friends_in_likers_count' => 2],
        ]);

        $this->mockLikers([10, 20]);

        $friends = $this->mock(VkFriendsService::class);
        $friends->shouldReceive('getFriendIdsWithError')->with(10)->once()->andReturn($this->transportFriends());
        $friends->shouldReceive('getFriendIdsWithError')->with(20)->once()->andReturn($this->okFriends([]));

        $this->mock(VkUsersService::class)->shouldReceive('getByIds')->never();

        $exitCode = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', $this->readResult()['run']['status']);

        $byUser = [];
        foreach (DB::table('user_post_segments')->get() as $row) {
            $byUser[(int) $row->user_id] = $row->segment;
        }
        $this->assertSame([
            10 => 'open',
            20 => 'core',
        ], $byUser);
    }

    public function test_users_get_flood_fails_without_upsert(): void
    {
        $this->mockLikers([10, 20]);

        $friends = $this->mock(VkFriendsService::class);
        $friends->shouldReceive('getFriendIdsWithError')->times(2)->andReturn($this->okFriends([]));

        $this->mock(VkUsersService::class)
            ->shouldReceive('getByIds')
            ->once()
            ->andThrow(new VkRequestException(
                'Flood control',
                VkRequestException::CATEGORY_FLOOD,
                9,
                false,
                true
            ));

        $exitCode = $this->runCommand();
        $result = $this->readResult();

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', $result['run']['status']);
        $this->assertSame('flood', $result['run']['stopped_by']);
        $this->assertSame(0, DB::table('user_post_segments')->count());
        $this->assertSame(0, $result['profiles']['requested']);
        $this->assertStringContainsString('Сегменты не сохранены: Flood control (код 9)', Artisan::output());
    }

    public function test_likes_flood_reports_typed_reason_and_run_status(): void
    {
        $this->mock(VkLikesService::class)
            ->shouldReceive('getPostLikers')
            ->once()
            ->andThrow(new VkRequestException(
                'Flood control',
                VkRequestException::CATEGORY_FLOOD,
                9,
                false,
                true
            ));

        $exitCode = $this->runCommand();
        $result = $this->readResult();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', $result['run']['status']);
        $this->assertSame('flood', $result['run']['stopped_by']);
        $this->assertSame(1, $result['run']['error_counts']['flood']);
        $this->assertSame(0, DB::table('user_post_segments')->count());
        $this->assertStringContainsString('Flood control (код 9)', $output);
        $this->assertStringNotContainsString('права токена', $output);
        $this->assertStringNotContainsString('неверные права', $output);
    }

    /**
     * @return array{friends:null,error:string,category:string,vk_code:null,stops_run:false,retryable:true}
     */
    private function transportFriends(): array
    {
        return [
            'friends' => null,
            'error' => 'Connection timed out',
            'category' => 'transport',
            'vk_code' => null,
            'stops_run' => false,
            'retryable' => true,
        ];
    }

    /**
     * @param array<int> $userIds
     */
    private function mockLikers(array $userIds): void
    {
        $this->mock(VkLikesService::class)
            ->shouldReceive('getPostLikers')
            ->once()
            ->andReturn([
                'total_count' => count($userIds),
                'user_ids' => $userIds,
            ]);
    }

    private function runCommand(): int
    {
        return Artisan::call('vk:likers-core', [
            '--owner' => '-670335',
            '--post' => 37639,
            '--k' => 1,
            '--delay' => 0,
            '--format' => 'json',
            '--output' => $this->outputPath,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readResult(): array
    {
        $this->assertFileExists($this->outputPath);

        return json_decode((string) file_get_contents($this->outputPath), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int> $friendIds
     * @return array{friends:array<int>,error:null,category:null,vk_code:null,stops_run:false,retryable:false}
     */
    private function okFriends(array $friendIds): array
    {
        return [
            'friends' => $friendIds,
            'error' => null,
            'category' => null,
            'vk_code' => null,
            'stops_run' => false,
            'retryable' => false,
        ];
    }

    /**
     * @return array{friends:null,error:string,category:string,vk_code:int,stops_run:true,retryable:false}
     */
    private function floodFriends(): array
    {
        return [
            'friends' => null,
            'error' => 'Flood control',
            'category' => 'flood',
            'vk_code' => 9,
            'stops_run' => true,
            'retryable' => false,
        ];
    }

    /**
     * @return array{friends:null,error:string,category:string,vk_code:int,stops_run:false,retryable:false}
     */
    private function privacyFriends(): array
    {
        return [
            'friends' => null,
            'error' => 'This profile is private',
            'category' => 'privacy',
            'vk_code' => 30,
            'stops_run' => false,
            'retryable' => false,
        ];
    }

    private function ensureTables(): void
    {
        if (!Schema::hasTable('user_post_segments')) {
            Schema::create('user_post_segments', function ($table) {
                $table->unsignedBigInteger('user_id');
                $table->string('owner_id', 32);
                $table->unsignedInteger('post_id');
                $table->string('segment', 16);
                $table->unsignedInteger('friends_in_likers_count')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['owner_id', 'post_id', 'user_id'], 'ups_owner_post_user');
            });
        }

        if (!Schema::hasTable('vk_posts')) {
            Schema::create('vk_posts', function ($table) {
                $table->id();
                $table->integer('post_id');
                $table->string('owner_id', 32);
                $table->integer('timestamp')->default(0);
                $table->timestamp('date');
                $table->text('text')->nullable();
                $table->integer('likes')->default(0);
                $table->integer('reposts')->default(0);
                $table->integer('comments')->default(0);
                $table->integer('views')->default(0);
                $table->string('url')->nullable();
                $table->timestamps();
            });
        }
    }
}
