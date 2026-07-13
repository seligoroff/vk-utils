<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\PostsGetAllGroups;
use App\Services\VkApi\VkGroupService;
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

class PostsGetAllGroupsClearTest extends TestCase
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

    public function test_wall_owner_id_is_negative_for_groups(): void
    {
        $meta = (object) ['type' => 'group', 'object_id' => 166471];
        $this->assertSame('-166471', VkGroupService::wallOwnerIdFromResolved($meta));
    }

    public function test_wall_owner_id_is_positive_for_users(): void
    {
        $meta = (object) ['type' => 'user', 'object_id' => 12345];
        $this->assertSame('12345', VkGroupService::wallOwnerIdFromResolved($meta));
    }

    public function test_clear_does_not_touch_owners_outside_allowlist(): void
    {
        $inCsv = '-100';
        $notInCsv = '-999';
        $ts = Carbon::parse('2024-06-15')->timestamp;
        $period = VkPostPeriod::fromCommandOptions('2024-06-01', '2024-07-01');

        $this->insertPost($inCsv, 1, $ts);
        $this->insertPost($notInCsv, 2, $ts);

        $command = new PostsGetAllGroups();
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $clear = new ReflectionMethod(PostsGetAllGroups::class, 'clearDatabaseForOwner');
        $clear->setAccessible(true);

        $clear->invoke($command, $inCsv, [$inCsv], $period);
        $clear->invoke($command, $notInCsv, [$inCsv], $period);

        $this->assertDatabaseMissing('vk_posts', ['owner_id' => $inCsv, 'post_id' => 1]);
        $this->assertDatabaseHas('vk_posts', ['owner_id' => $notInCsv, 'post_id' => 2]);
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
