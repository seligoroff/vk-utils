<?php

namespace Tests\Unit\Support;

use App\Support\VkWallPost;
use PHPUnit\Framework\TestCase;

class VkWallPostTest extends TestCase
{
    public function test_old_pinned_post_does_not_stop_pagination(): void
    {
        $post = (object) [
            'date' => strtotime('2020-01-01'),
            'is_pinned' => 1,
        ];

        $from = strtotime('2025-01-01');

        $this->assertFalse(VkWallPost::shouldStopPagination($post, $from));
    }

    public function test_old_regular_post_stops_pagination(): void
    {
        $post = (object) [
            'date' => strtotime('2020-01-01'),
            'is_pinned' => 0,
        ];

        $from = strtotime('2025-01-01');

        $this->assertTrue(VkWallPost::shouldStopPagination($post, $from));
    }
}
