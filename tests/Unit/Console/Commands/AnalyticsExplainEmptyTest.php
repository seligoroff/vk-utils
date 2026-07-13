<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\Analytics;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AnalyticsExplainEmptyTest extends TestCase
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

    public function test_explains_when_no_posts_for_owner(): void
    {
        [$command, $output] = $this->createCommandWithBufferedOutput();

        $method = (new ReflectionClass($command))->getMethod('explainEmptyPostsResult');
        $method->setAccessible(true);
        $method->invoke($command, '-100', Carbon::parse('2024-06-01'), Carbon::parse('2024-07-01'), 0);

        $this->assertStringContainsString('записей нет', $output->fetch());
    }

    public function test_explains_when_posts_exist_outside_period(): void
    {
        [$command, $output] = $this->createCommandWithBufferedOutput();

        $this->insertPost('-100', 1, Carbon::parse('2024-01-15')->timestamp);
        $this->insertPost('-100', 2, Carbon::parse('2024-02-15')->timestamp);

        $method = (new ReflectionClass($command))->getMethod('explainEmptyPostsResult');
        $method->setAccessible(true);
        $method->invoke($command, '-100', Carbon::parse('2024-06-01'), Carbon::parse('2024-07-01'), 0);

        $buffer = $output->fetch();
        $this->assertStringContainsString('в БД есть', $buffer);
        $this->assertStringContainsString('Даты постов в БД', $buffer);
    }

    public function test_explains_when_posts_filtered_by_min_engagement(): void
    {
        [$command, $output] = $this->createCommandWithBufferedOutput();

        $this->insertPost('-100', 1, Carbon::parse('2024-06-15')->timestamp, ['likes' => 1, 'reposts' => 0, 'comments' => 0]);

        $method = (new ReflectionClass($command))->getMethod('explainEmptyPostsResult');
        $method->setAccessible(true);
        $method->invoke($command, '-100', Carbon::parse('2024-06-01'), Carbon::parse('2024-07-01'), 10);

        $buffer = $output->fetch();
        $this->assertStringContainsString('--min-engagement=10', $buffer);
        $this->assertStringContainsString('уменьшите или уберите', $buffer);
    }

    public function test_suggests_load_command_with_to_option(): void
    {
        [$command, $output] = $this->createCommandWithBufferedOutput();

        $method = (new ReflectionClass($command))->getMethod('explainEmptyPostsResult');
        $method->setAccessible(true);
        $method->invoke($command, '-100', Carbon::parse('2024-06-01'), Carbon::parse('2024-07-01'), 0);

        $buffer = $output->fetch();
        $this->assertStringContainsString('vk:posts-get --owner=-100 --from=2024-06-01 --to=2024-07-01 --db', $buffer);
    }

    private function createCommandWithBufferedOutput(): array
    {
        $command = new Analytics();
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        return [$command, $output];
    }

    private function insertPost(string $ownerId, int $postId, int $timestamp, array $overrides = []): void
    {
        DB::table('vk_posts')->insert([
            'post_id' => $postId,
            'owner_id' => $ownerId,
            'timestamp' => $timestamp,
            'date' => Carbon::createFromTimestamp($timestamp)->toDateTimeString(),
            'text' => 'test',
            'likes' => $overrides['likes'] ?? 0,
            'reposts' => $overrides['reposts'] ?? 0,
            'comments' => $overrides['comments'] ?? 0,
            'url' => "https://vk.com/wall{$ownerId}_{$postId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
