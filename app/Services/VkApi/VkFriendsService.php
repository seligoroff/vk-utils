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
     * @return array{
     *   friends:?array<int>,
     *   error:?string,
     *   category:?string,
     *   vk_code:?int,
     *   stops_run:bool,
     *   retryable:bool
     * }
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
            }, "getting friends for user {$userId}", ['retry' => true]);

            if (!is_array($result)) {
                return $this->errorResult(
                    new VkRequestException(
                        'Unexpected VK response format',
                        VkRequestException::CATEGORY_UNEXPECTED_RESPONSE,
                        null,
                        false,
                        true
                    )
                );
            }

            if (isset($result['items']) && is_array($result['items'])) {
                return $this->successResult(array_values(array_map('intval', $result['items'])));
            }

            return $this->successResult([]);
        } catch (\Throwable $e) {
            return $this->errorResult(VkErrorClassifier::fromThrowable($e));
        }
    }

    /**
     * @param array<int> $friends
     * @return array{friends:array<int>,error:null,category:null,vk_code:null,stops_run:false,retryable:false}
     */
    private function successResult(array $friends): array
    {
        return [
            'friends' => $friends,
            'error' => null,
            'category' => null,
            'vk_code' => null,
            'stops_run' => false,
            'retryable' => false,
        ];
    }

    /**
     * @return array{friends:null,error:string,category:string,vk_code:?int,stops_run:bool,retryable:bool}
     */
    private function errorResult(VkRequestException $e): array
    {
        return [
            'friends' => null,
            'error' => $e->getMessage(),
            'category' => $e->category,
            'vk_code' => $e->vkCode,
            'stops_run' => $e->stopsRun,
            'retryable' => $e->retryable,
        ];
    }
}

