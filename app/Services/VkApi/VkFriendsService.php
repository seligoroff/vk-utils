<?php

namespace App\Services\VkApi;

/**
 * VK Friends API Service
 * Handles operations with users' friend lists.
 */
class VkFriendsService
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
     * Get friend IDs for a user.
     *
     * @param int $userId User ID
     * @param int $count Number of users to return (max 5000 per API call)
     * @param int $offset Offset for pagination
     * @return array<int>|null
     */
    public function getFriendIds(int $userId, int $count = 5000, int $offset = 0): ?array
    {
        $result = $this->getFriendIdsWithError($userId, $count, $offset);
        return $result['friends'];
    }

    /**
     * Get friend IDs for a user with error details.
     *
     * @param int $userId User ID
     * @param int $count Number of users to return (max 5000 per API call)
     * @param int $offset Offset for pagination
     * @return array{friends:array<int>|null,error:string|null}
     */
    public function getFriendIdsWithError(int $userId, int $count = 5000, int $offset = 0): array
    {
        $adapter = $this->getAdapter();

        try {
            $result = $adapter->execute(function () use ($adapter, $userId, $count, $offset) {
                return $adapter->friends()->get(
                    $adapter->getToken(),
                    [
                        'user_id' => $userId,
                        'count' => $count,
                        'offset' => $offset,
                    ]
                );
            }, "getting friends for user {$userId}");

            if (!is_array($result)) {
                return ['friends' => null, 'error' => 'Unexpected VK response format'];
            }

            if (isset($result['items']) && is_array($result['items'])) {
                return [
                    'friends' => array_values(array_map('intval', $result['items'])),
                    'error' => null,
                ];
            }

            return ['friends' => [], 'error' => null];
        } catch (\Exception $e) {
            return ['friends' => null, 'error' => $e->getMessage()];
        }
    }
}

