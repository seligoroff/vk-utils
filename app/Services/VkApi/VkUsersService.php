<?php

namespace App\Services\VkApi;

/**
 * VK Users API Service
 * Handles operations with users profiles.
 */
class VkUsersService
{
    private const USERS_PER_REQUEST = 100;

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
     * Get users profiles by IDs.
     *
     * @param array<int> $userIds
     * @param array<string> $fields
     * @param float $delaySeconds Delay between batch requests (not before the first)
     * @return array<int, array<string, mixed>>
     */
    public function getByIds(array $userIds, array $fields = ['screen_name'], float $delaySeconds = 0.0): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return [];
        }

        $adapter = $this->getAdapter();
        $profiles = [];

        // Conservatively batch users.get: large user_ids lists are truncated by VK
        $chunks = array_chunk($userIds, self::USERS_PER_REQUEST);
        foreach ($chunks as $index => $chunk) {
            if ($index > 0 && $delaySeconds > 0) {
                usleep((int) ($delaySeconds * 1_000_000));
            }

            try {
                $result = $adapter->execute(function () use ($adapter, $chunk, $fields) {
                    return $adapter->users()->get(
                        $adapter->getToken(),
                        [
                            'user_ids' => $chunk,
                            'fields' => $fields,
                        ]
                    );
                }, 'getting users profiles');
            } catch (\Exception $e) {
                continue;
            }

            if (!is_array($result)) {
                continue;
            }

            foreach ($result as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }
                $profiles[(int) $row['id']] = $row;
            }
        }

        return $profiles;
    }
}

