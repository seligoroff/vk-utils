<?php

namespace App\Services\VkApi;

/**
 * VK Photo API Service
 * Handles operations with photo albums
 */
class VkPhotoService extends VkApiClient
{
    /**
     * Get photo albums for owner
     * 
     * @param string|int $ownerId Owner ID (use negative for communities)
     * @param array $params Additional parameters (need_system, need_covers, count, offset, album_ids)
     * @return array|null
     */
    public function getAlbums($ownerId, array $params = []): ?array
    {
        $apiParams = array_merge([
            'owner_id' => $ownerId,
        ], $params);
        
        $response = self::apiGet('photos.getAlbums', $apiParams);
        
        $data = self::parseResponse($response);
        return $data->items ?? null;
    }
    
    /**
     * Get all albums with pagination
     * 
     * @param string|int $ownerId Owner ID
     * @param array $params Additional parameters
     * @return array
     */
    public function getAllAlbums($ownerId, array $params = []): array
    {
        $allAlbums = [];
        $offset = 0;
        $count = $params['count'] ?? 100;
        
        while (true) {
            $albums = $this->getAlbums($ownerId, array_merge($params, [
                'offset' => $offset,
                'count' => $count
            ]));
            
            if (empty($albums) || !is_array($albums)) {
                break;
            }
            
            $allAlbums = array_merge($allAlbums, $albums);
            
            // Если получили меньше запрошенного, значит это последняя страница
            if (count($albums) < $count) {
                break;
            }
            
            $offset += $count;
        }
        
        return $allAlbums;
    }
}


