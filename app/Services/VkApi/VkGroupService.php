<?php

namespace App\Services\VkApi;

/**
 * VK Groups API Service
 * Handles operations with groups and communities
 * 
 * Migrated to use vkcom/vk-php-sdk via VkSdkAdapter
 */
class VkGroupService
{
    private static ?VkSdkAdapter $adapter = null;

    /**
     * Set adapter instance (for testing)
     * 
     * @param VkSdkAdapter|null $adapter
     * @return void
     */
    public static function setAdapter(?VkSdkAdapter $adapter): void
    {
        self::$adapter = $adapter;
    }

    /**
     * Get SDK adapter instance
     * 
     * @return VkSdkAdapter
     */
    private static function getAdapter(): VkSdkAdapter
    {
        if (self::$adapter === null) {
            self::$adapter = new VkSdkAdapter();
        }
        return self::$adapter;
    }

    /**
     * Resolve screen name to VK object
     * 
     * @param string $screenName Screen name (e.g., 'durov')
     * @return object|null Returns object with 'type' and 'object_id' properties, or null on error
     */
    public static function resolveName(string $screenName)
    {
        $adapter = self::getAdapter();
        
        try {
            $result = $adapter->execute(function() use ($adapter, $screenName) {
                return $adapter->utils()->resolveScreenName(
                    $adapter->getToken(),
                    ['screen_name' => $screenName]
                );
            }, "resolving screen name '{$screenName}'");
            
            // Convert array to object for backward compatibility
            if (is_array($result)) {
                return (object)$result;
            }
            
            return $result;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }

    /**
     * Owner ID for wall API and vk_posts: negative for communities, positive for users.
     *
     * @param object $resolved Result of resolveName() with object_id and type
     * @return string|null
     */
    public static function wallOwnerIdFromResolved(object $resolved): ?string
    {
        if (!isset($resolved->object_id)) {
            return null;
        }

        $id = (int) $resolved->object_id;
        if ($id === 0) {
            return null;
        }

        $type = $resolved->type ?? 'group';

        if ($type === 'user') {
            return (string) $id;
        }

        return '-' . abs($id);
    }
    
    /**
     * Get group information by ID
     * 
     * @param int|string $groupId Group ID
     * @param array $fields Additional fields to return (e.g., ['members_count', 'description'])
     * @return array|object|null Returns array/object with group data, or null on error
     */
    public static function getById($groupId, array $fields = [])
    {
        $adapter = self::getAdapter();
        
        $params = ['group_id' => $groupId];
        
        if (!empty($fields)) {
            // Валидация: fields должен быть массивом строк
            $validFields = array_filter($fields, function($field) {
                return is_string($field) && !empty(trim($field));
            });
            
            if (!empty($validFields)) {
                // SDK ожидает массив полей
                $params['fields'] = array_values($validFields);
            }
        }
        
        try {
            $result = $adapter->execute(function() use ($adapter, $params) {
                return $adapter->groups()->getById(
                    $adapter->getToken(),
                    $params
                );
            }, "getting group by ID {$groupId}");
            
            // SDK returns array of groups, return first one for backward compatibility
            if (is_array($result) && !empty($result)) {
                $group = $result[0];
                // Convert array to object if needed for backward compatibility
                if (is_array($group)) {
                    return (object)$group;
                }
                return $group;
            }
            
            return null;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
    
    /**
     * Get user's groups
     * 
     * @param int $userId User ID
     * @return array|object|null Returns groups data, or null on error
     */
    public function getUserGroups(int $userId)
    {
        $adapter = self::getAdapter();
        
        try {
            $result = $adapter->execute(function() use ($adapter, $userId) {
                return $adapter->groups()->get(
                    $adapter->getToken(),
                    ['user_id' => $userId]
                );
            }, "getting groups for user {$userId}");
            
            // Convert array to object if needed for backward compatibility
            if (is_array($result)) {
                return $result;
            }
            
            return $result;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
}

