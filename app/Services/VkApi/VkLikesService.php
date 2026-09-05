<?php

namespace App\Services\VkApi;

/**
 * VK Likes API Service
 * Handles operations with likes lists.
 */
class VkLikesService
{
    private ?VkSdkAdapter $adapter = null;

    /**
     * Set SDK adapter instance (for testing)
     */
    public function setAdapter(?VkSdkAdapter $adapter): void
    {
        $this->adapter = $adapter;
    }

    /**
     * Get SDK adapter instance
     */
    private function getAdapter(): VkSdkAdapter
    {
        if ($this->adapter === null) {
            $this->adapter = new VkSdkAdapter();
        }

        return $this->adapter;
    }

    /**
     * Get users who liked a post.
     *
     * @param int|string $ownerId Owner ID (negative for communities)
     * @param int $postId Post ID
     * @param int $count Number of users to return (max 1000 per API call)
     * @param int $offset Offset for pagination
     * @return array{total_count:int,user_ids:array<int>}
     * @throws VkRequestException
     */
    public function getPostLikers($ownerId, int $postId, int $count = 1000, int $offset = 0): array
    {
        $adapter = $this->getAdapter();

        try {
            $result = $adapter->execute(function () use ($adapter, $ownerId, $postId, $count, $offset) {
                return $adapter->likes()->getList(
                    $adapter->getToken(),
                    [
                        'type' => 'post',
                        'owner_id' => $ownerId,
                        'item_id' => $postId,
                        'filter' => 'likes',
                        'extended' => 0,
                        'count' => $count,
                        'offset' => $offset,
                    ]
                );
            }, "getting post likers for {$ownerId}_{$postId}", ['retry' => true]);

            if (!is_array($result)) {
                throw new VkRequestException(
                    'Unexpected VK response format',
                    VkRequestException::CATEGORY_UNEXPECTED_RESPONSE,
                    null,
                    false,
                    true
                );
            }

            $totalCount = isset($result['count']) ? (int) $result['count'] : 0;
            $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
            $userIds = array_values(array_map('intval', $items));

            return [
                'total_count' => $totalCount,
                'user_ids' => $userIds,
            ];
        } catch (VkRequestException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw VkErrorClassifier::fromThrowable($e);
        }
    }
}
