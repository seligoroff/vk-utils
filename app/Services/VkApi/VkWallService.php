<?php

namespace App\Services\VkApi;

/**
 * VK Wall API Service
 * Handles operations with wall posts and comments
 */
class VkWallService extends VkApiClient
{
    private $ownerId;
    
    /**
     * Set owner ID for wall operations
     * 
     * @param string|int $ownerId Owner ID (use negative for communities)
     * @return self
     */
    public function setOwner($ownerId): self
    {
        $this->ownerId = $ownerId;
        return $this;
    }
    
    /**
     * Get wall posts
     * 
     * @param int $count Number of posts to return
     * @param int $offset Offset for pagination
     * @return array|null
     */
    public function getPosts(int $count = 100, int $offset = 0): ?array
    {
        $response = self::apiGet('wall.get', [
            'owner_id' => $this->ownerId,
            'offset' => $offset,
            'count' => $count
        ]);
        
        $data = self::parseResponse($response);
        return $data->items ?? null;
    }
    
    /**
     * Get comments for a post
     * 
     * @param int $postId Post ID
     * @param int $count Number of comments to return
     * @param int $offset Offset for pagination
     * @return array|null
     */
    public function getComments(int $postId, int $count = 100, int $offset = 0): ?array
    {
        $response = self::apiGet('wall.getComments', [
            'owner_id' => $this->ownerId,
            'post_id' => $postId,
            'offset' => $offset,
            'count' => $count
        ]);
        
        $data = self::parseResponse($response);
        return $data->items ?? null;
    }
    
    /**
     * Get single comment
     * 
     * @param int $commentId Comment ID
     * @return mixed|null
     */
    public function getComment(int $commentId)
    {
        $response = self::apiGet('wall.getComment', [
            'owner_id' => $this->ownerId,
            'comment_id' => $commentId
        ]);
        
        return self::parseResponse($response);
    }
    
    /**
     * Pin post on the wall
     * 
     * @param int $postId Post ID to pin
     * @return mixed
     */
    public function pinPost(int $postId)
    {
        sleep(1); // Rate limiting
        
        $response = self::apiGet('wall.pin', [
            'post_id' => $postId,
            'owner_id' => $this->ownerId
        ]);
        
        return self::parseResponse($response);
    }
}

