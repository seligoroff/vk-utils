<?php

namespace App\Support;

/**
 * Helpers for VK wall.get items (pinned posts break chronological order).
 */
class VkWallPost
{
    public static function timestamp(object $post): ?int
    {
        if (!isset($post->date)) {
            return null;
        }

        return (int) $post->date;
    }

    public static function isPinned(object $post): bool
    {
        return (int) ($post->is_pinned ?? 0) === 1;
    }

    /**
     * Stop pagination: reached chronologically old posts (not pinned out-of-order items).
     */
    public static function shouldStopPagination(object $post, int $fromInclusive): bool
    {
        $timestamp = self::timestamp($post);

        if ($timestamp === null) {
            return false;
        }

        return $timestamp < $fromInclusive && !self::isPinned($post);
    }

    public static function formatTimestamp(?int $timestamp): string
    {
        if ($timestamp === null) {
            return 'n/a';
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
