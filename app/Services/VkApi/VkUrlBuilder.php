<?php

namespace App\Services\VkApi;

/**
 * VK URL Builder
 * Builds URLs for viewing VK content in browser (not API)
 */
class VkUrlBuilder
{
    /**
     * Build URL for viewing wall post
     * 
     * @param string|int $ownerId Owner ID (negative for communities)
     * @param int $postId Post ID
     * @param string|null $accountBaseUrl Account base URL (optional, uses config if not provided)
     * @return string
     */
    public static function wallPost($ownerId, int $postId, ?string $accountBaseUrl = null): string
    {
        $accountBaseUrl = $accountBaseUrl ?? config('vk.account_base_url', 'https://vk.com');
        return "{$accountBaseUrl}?w=wall{$ownerId}_{$postId}";
    }
    
    /**
     * Build URL for viewing wall post comment
     * 
     * @param string|int $ownerId Owner ID (negative for communities)
     * @param int $postId Post ID
     * @param int $commentId Comment ID
     * @param string|null $accountBaseUrl Account base URL (optional, uses config if not provided)
     * @return string
     */
    public static function wallComment($ownerId, int $postId, int $commentId, ?string $accountBaseUrl = null): string
    {
        $accountBaseUrl = $accountBaseUrl ?? config('vk.account_base_url', 'https://vk.com');
        return "{$accountBaseUrl}/wall{$ownerId}_{$postId}?reply={$commentId}";
    }
}

