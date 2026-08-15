<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostsListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('vk_posts')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2024_01_01_000000_create_vk_posts_table.php',
                '--force' => true,
            ]);
        }

        DB::table('vk_posts')->delete();
    }

    public function test_lists_only_owner_posts_inside_half_open_period_from_database(): void
    {
        $this->insertPost('-100', 1, '2026-06-30 23:59:59', 'До периода');
        $this->insertPost('-100', 2, '2026-07-01 00:00:00', 'Первый пост');
        $this->insertPost('-100', 3, '2026-07-31 12:00:00', 'Второй пост');
        $this->insertPost('-100', 4, '2026-08-01 00:00:00', 'После периода');
        $this->insertPost('-200', 5, '2026-07-15 12:00:00', 'Другая группа');

        $exitCode = Artisan::call('vk:posts-list', [
            '--owner' => '-100',
            '--from' => '2026-07-01',
            '--to' => '2026-08-01',
            '--format' => 'json',
        ]);

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $result['pagination']['total']);
        $this->assertSame(['Первый пост', 'Второй пост'], array_column($result['posts'], 'text'));
        $this->assertFalse($result['pagination']['has_more']);
    }

    public function test_applies_pagination_and_descending_order(): void
    {
        $this->insertPost('-100', 1, '2026-07-01 10:00:00', 'Первый');
        $this->insertPost('-100', 2, '2026-07-02 10:00:00', 'Второй');
        $this->insertPost('-100', 3, '2026-07-03 10:00:00', 'Третий');

        $exitCode = Artisan::call('vk:posts-list', [
            '--owner' => '-100',
            '--from' => '2026-07-01',
            '--to' => '2026-08-01',
            '--limit' => 1,
            '--offset' => 1,
            '--order' => 'desc',
            '--format' => 'json',
        ]);

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, $result['pagination']['total']);
        $this->assertSame(1, $result['pagination']['returned']);
        $this->assertTrue($result['pagination']['has_more']);
        $this->assertSame(2, $result['posts'][0]['post_id']);
    }

    public function test_requires_positive_limit(): void
    {
        $exitCode = Artisan::call('vk:posts-list', [
            '--owner' => '-100',
            '--from' => '2026-07-01',
            '--limit' => 0,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--limit должен быть больше нуля', Artisan::output());
    }

    private function insertPost(string $ownerId, int $postId, string $date, string $text): void
    {
        $timestamp = Carbon::parse($date)->timestamp;

        DB::table('vk_posts')->insert([
            'post_id' => $postId,
            'owner_id' => $ownerId,
            'timestamp' => $timestamp,
            'date' => Carbon::createFromTimestamp($timestamp)->toDateTimeString(),
            'text' => $text,
            'likes' => $postId,
            'reposts' => $postId,
            'comments' => $postId,
            'url' => "https://vk.com/wall{$ownerId}_{$postId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
