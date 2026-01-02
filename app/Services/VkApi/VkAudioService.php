<?php

namespace App\Services\VkApi;

/**
 * VK Audio API Service
 * Handles operations with audio files
 */
class VkAudioService extends VkApiClient
{
    /**
     * Add audio to group or user
     * 
     * @param object $audioAttach Audio attachment object
     * @param string|null $groupId Group ID (optional)
     * @return mixed
     */
    public function add($audioAttach, ?string $groupId = null)
    {
        sleep(1); // Rate limiting
        
        $params = [
            'audio_id' => $audioAttach->audio->id,
            'owner_id' => $audioAttach->audio->owner_id
        ];
        
        if ($groupId) {
            $params['group_id'] = $groupId;
        }
        
        $response = self::apiGet('audio.add', $params);
        
        return self::parseResponse($response);
    }
}


