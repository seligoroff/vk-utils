<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\GetPosts;
use App\Support\VkPostPeriod;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class GetPostsClearTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('vk_posts')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2024_01_01_000000_create_vk_posts_table.php',
                '--force' => true,
            ]);
        }

        DB::table('vk_posts')->delete();
    }

    public function test_clear_removes_only_owner_posts_within_period(): void
    {
        $owner = '-100';
        $otherOwner = '-200';
        $inPeriod = Carbon::parse('2024-06-15')->timestamp;
        $beforePeriod = Carbon::parse('2024-05-01')->timestamp;
        $afterPeriod = Carbon::parse('2024-07-01')->timestamp;

        $this->insertPost($owner, 1, $beforePeriod);
        $this->insertPost($owner, 2, $inPeriod);
        $this->insertPost($owner, 3, $afterPeriod);
        $this->insertPost($otherOwner, 4, $inPeriod);

        $command = new class($owner) extends GetPosts {
            public function __construct(private string $ownerId)
            {
                parent::__construct();
            }

            public function option($key = null, $default = null)
            {
                if ($key === 'owner') {
                    return $this->ownerId;
                }

                return parent::option($key, $default);
            }

            public function runClearDatabase(VkPostPeriod $period): void
            {
                $method = new ReflectionMethod(GetPosts::class, 'clearDatabase');
                $method->setAccessible(true);
                $method->invoke($this, $period);
            }
        };

        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));
        $period = VkPostPeriod::fromCommandOptions('2024-06-01', '2024-07-01');
        $command->runClearDatabase($period);

        $this->assertDatabaseHas('vk_posts', ['owner_id' => $owner, 'post_id' => 1]);
        $this->assertDatabaseMissing('vk_posts', ['owner_id' => $owner, 'post_id' => 2]);
        $this->assertDatabaseHas('vk_posts', ['owner_id' => $owner, 'post_id' => 3]);
        $this->assertDatabaseHas('vk_posts', ['owner_id' => $otherOwner, 'post_id' => 4]);
    }

    public function test_adjacent_year_periods_do_not_clear_each_other(): void
    {
        $owner = '-2507736';
        $ts2025 = Carbon::parse('2025-06-15')->timestamp;
        $ts2026 = Carbon::parse('2026-06-15')->timestamp;
        $ts2024 = Carbon::parse('2024-06-15')->timestamp;

        $this->insertPost($owner, 1, $ts2024);
        $this->insertPost($owner, 2, $ts2025);
        $this->insertPost($owner, 3, $ts2026);

        $command = new class($owner) extends GetPosts {
            public function __construct(private string $ownerId)
            {
                parent::__construct();
            }

            public function option($key = null, $default = null)
            {
                return $key === 'owner' ? $this->ownerId : parent::option($key, $default);
            }

            public function runClearDatabase(VkPostPeriod $period): void
            {
                $method = new ReflectionMethod(GetPosts::class, 'clearDatabase');
                $method->setAccessible(true);
                $method->invoke($this, $period);
            }
        };
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $command->runClearDatabase(VkPostPeriod::fromCommandOptions('2025-01-01', '2026-01-01'));

        $this->assertDatabaseHas('vk_posts', ['owner_id' => $owner, 'post_id' => 1]);
        $this->assertDatabaseMissing('vk_posts', ['owner_id' => $owner, 'post_id' => 2]);
        $this->assertDatabaseHas('vk_posts', ['owner_id' => $owner, 'post_id' => 3]);
    }

    private function insertPost(string $ownerId, int $postId, int $timestamp): void
    {
        DB::table('vk_posts')->insert([
            'post_id' => $postId,
            'owner_id' => $ownerId,
            'timestamp' => $timestamp,
            'date' => Carbon::createFromTimestamp($timestamp)->toDateTimeString(),
            'text' => 'test',
            'likes' => 0,
            'reposts' => 0,
            'comments' => 0,
            'url' => "https://vk.com/wall{$ownerId}_{$postId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
