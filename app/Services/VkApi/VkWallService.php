<?php

namespace App\Services\VkApi;

use App\Exceptions\Vk\VkApiException;
use App\Exceptions\Vk\VkTransportException;
use App\Exceptions\Vk\VkUnexpectedResponseException;
use Illuminate\Support\Facades\Log;

/**
 * VK Wall API Service
 * Handles operations with wall posts and comments
 * 
 * Migrated to use vkcom/vk-php-sdk via VkSdkAdapter
 */
class VkWallService
{
    /** Maximum total attempts including the initial request (2 = initial + 1 retry). */
    private const MAX_ATTEMPTS = 2;

    /** Delay between attempts in microseconds (500 ms). */
    private const RETRY_DELAY_US = 500_000;

    private $ownerId;
    private ?VkSdkAdapter $adapter = null;

    /**
     * Set SDK adapter instance (for testing)
     * 
     * @param VkSdkAdapter|null $adapter
     * @return void
     */
    public function setAdapter(?VkSdkAdapter $adapter): void
    {
        $this->adapter = $adapter;
    }

    /**
     * Get SDK adapter instance
     * 
     * @return VkSdkAdapter
     */
    private function getAdapter(): VkSdkAdapter
    {
        if ($this->adapter === null) {
            $this->adapter = new VkSdkAdapter();
        }
        return $this->adapter;
    }
    
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
     * Get wall posts.
     *
     * Returns an array of post objects on success, an empty array when
     * the wall has no posts in the requested range, and throws a domain
     * exception on any API, network, or unexpected error.
     *
     * Transient errors (timeout, rate limit) are retried up to
     * MAX_ATTEMPTS - 1 times (2 total attempts by default).
     *
     * @param int $count  Number of posts to return
     * @param int $offset Offset for pagination
     * @return array Array of post objects (empty array = successful empty wall)
     *
     * @throws VkApiException             VK API returned an error response
     * @throws VkTransportException       Network-level failure
     * @throws VkUnexpectedResponseException  Response missing required keys
     */
    public function getPosts(int $count = 100, int $offset = 0): array
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::MAX_ATTEMPTS) {
            $attempts++;

            try {
                return $this->fetchPage($count, $offset);
            } catch (VkUnexpectedResponseException $e) {
                // No retry for malformed responses — log once and re-throw
                $this->logFinalError($e, $count, $offset, 1);
                throw $e;
            } catch (VkTransportException $e) {
                $lastException = $e;
                if (!$this->isRetryableTransport($e)) {
                    break;
                }
            } catch (VkApiException $e) {
                $lastException = $e;
                if ($e->getReason() !== VkApiException::REASON_RATE_LIMIT) {
                    break;
                }
            }

            // Only sleep if another attempt will follow
            if ($attempts < self::MAX_ATTEMPTS) {
                usleep(self::RETRY_DELAY_US);
            }
        }

        // All attempts exhausted — log and re-throw with attempt count
        $this->logFinalError($lastException, $count, $offset, $attempts);
        throw $this->withAttempts($lastException, $attempts);
    }

    /**
     * Perform a single wall.get call and map SDK exceptions to domain exceptions.
     *
     * @return array
     */
    private function fetchPage(int $count, int $offset): array
    {
        $adapter = $this->getAdapter();
        $token   = $adapter->getToken();

        try {
            $result = $adapter->wall()->get($token, [
                'owner_id' => $this->ownerId,
                'offset'   => $offset,
                'count'    => $count,
            ]);
        } catch (\VK\Exceptions\VKApiException $e) {
            throw $this->mapApiException($e);
        } catch (\VK\Exceptions\VKClientException $e) {
            throw $this->mapTransportException($e);
        }

        // Successful HTTP response — validate structure
        if (!is_array($result) || !isset($result['items']) || !is_array($result['items'])) {
            throw new VkUnexpectedResponseException(
                'VK API returned an unexpected response structure (missing or invalid items key).'
            );
        }

        return array_map(function ($item) {
            return $this->arrayToObject($item);
        }, $result['items']);
    }

    /**
     * Map a VK SDK API exception to our domain VkApiException.
     */
    private function mapApiException(\VK\Exceptions\VKApiException $e): VkApiException
    {
        $code   = $e->getCode();
        $reason = VkApiException::REASON_OTHER;

        // Both code 6 and code 29 are rate-limit responses.
        // The SDK also throws a dedicated VKApiRateLimitException for code 29.
        if ($code === 6 || $code === 29 || $e instanceof \VK\Exceptions\Api\VKApiRateLimitException) {
            $reason = VkApiException::REASON_RATE_LIMIT;
        } elseif ($code === 15) {
            $reason = VkApiException::REASON_ACCESS_DENIED;
        } elseif ($code === 5) {
            $reason = VkApiException::REASON_INVALID_TOKEN;
        }

        $userMessage = match ($reason) {
            VkApiException::REASON_RATE_LIMIT =>
                'VK API временно ограничил частоту запросов. Повторите команду позже или увеличьте задержку.',
            VkApiException::REASON_ACCESS_DENIED =>
                'Доступ к стене запрещён. Проверьте owner ID и права токена.',
            VkApiException::REASON_INVALID_TOKEN =>
                'Токен недействителен или не имеет прав доступа к стене.',
            default =>
                "VK API error (code: {$code}).",
        };

        return new VkApiException($userMessage, $reason, $code, $e);
    }

    /**
     * Map a VK SDK client/transport exception to our domain VkTransportException.
     *
     * The raw SDK/Guzzle message MUST NOT be passed through — it can contain
     * stack traces, full URLs, and other internal details.
     */
    private function mapTransportException(\VK\Exceptions\VKClientException $e): VkTransportException
    {
        $msg    = $e->getMessage();
        $reason = VkTransportException::REASON_OTHER;

        // Guzzle/curl errors have recognizable patterns
        if (str_contains($msg, 'timed out') || str_contains($msg, 'cURL error 28')) {
            $reason = VkTransportException::REASON_TIMEOUT;
        } elseif (str_contains($msg, 'cURL error 7') || str_contains($msg, 'cURL error 52')
            || str_contains($msg, 'Connection') || str_contains($msg, 'connect')) {
            $reason = VkTransportException::REASON_CONNECTION;
        }

        // Never pass through the raw Guzzle/SDK message — it contains stack traces
        $userMessage = match ($reason) {
            VkTransportException::REASON_TIMEOUT =>
                'Превышено время ожидания ответа от VK API.',
            VkTransportException::REASON_CONNECTION =>
                'Не удалось установить соединение с VK API.',
            default =>
                'Сетевая ошибка при обращении к VK API.',
        };

        return new VkTransportException($userMessage, $reason, $e);
    }

    /**
     * Whether a transport exception is eligible for retry.
     */
    private function isRetryableTransport(VkTransportException $e): bool
    {
        return in_array($e->getReason(), [
            VkTransportException::REASON_TIMEOUT,
            VkTransportException::REASON_CONNECTION,
        ], true);
    }

    /**
     * Strip potential secrets from an error message.
     */
    private function safeErrorMessage(string $raw): string
    {
        // Replace access_token value if present in the message
        $safe = preg_replace(
            '/access_token=[^&\s\"]+/i',
            'access_token=[REDACTED]',
            $raw
        );

        return $safe ?? $raw;
    }

    /**
     * Log the final error after all retries are exhausted.
     *
     * Logged context MUST NOT contain the token, raw SDK/Guzzle message,
     * URL, query string, request body, or the full $previous throwable.
     */
    private function logFinalError(
        \Throwable $exception,
        int $count,
        int $offset,
        int $attempts
    ): void {
        $context = [
            'operation'     => 'wall.get',
            'owner_id'      => $this->ownerId,
            'count'         => $count,
            'offset'        => $offset,
            'attempts'      => $attempts,
            'exception_class' => get_class($exception),
            'api_timeout'   => config('vk.api_timeout'),
            'api_connect_timeout' => config('vk.api_connect_timeout'),
        ];

        if ($exception instanceof VkApiException) {
            $context['vkreason']  = $exception->getReason();
            $context['vk_api_code'] = $exception->getApiCode();
        } elseif ($exception instanceof VkTransportException) {
            $context['vkreason'] = $exception->getReason();
        }

        $level = ($exception instanceof VkTransportException)
            ? 'warning'
            : 'error';

        Log::log($level, 'VK wall.get failed after retries', $context);
    }

    /**
     * Return a copy of the exception with attempt count appended to the message.
     */
    private function withAttempts(\Throwable $e, int $attempts): \Throwable
    {
        $class = get_class($e);
        $msg   = $e->getMessage() . ' Выполнено попыток: ' . $attempts . '.';

        if ($e instanceof VkApiException) {
            return new VkApiException($msg, $e->getReason(), $e->getApiCode(), $e);
        }
        if ($e instanceof VkTransportException) {
            return new VkTransportException($msg, $e->getReason(), $e);
        }

        // Unexpected exception type — wrap generically (should not happen in practice)
        return new \RuntimeException($msg, 0, $e);
    }
    
    /**
     * Recursively convert array to object
     * This ensures nested arrays (like likes, reposts, comments) are also converted to objects
     * 
     * @param mixed $data
     * @return mixed
     */
    private function arrayToObject($data)
    {
        if (is_array($data)) {
            return (object) array_map([$this, 'arrayToObject'], $data);
        }
        return $data;
    }
    
    /**
     * Get comments for a post
     * 
     * @param int $postId Post ID
     * @param int $count Number of comments to return
     * @param int $offset Offset for pagination
     * @return array|null Returns array of comment objects, or null on error
     */
    public function getComments(int $postId, int $count = 100, int $offset = 0): ?array
    {
        $adapter = $this->getAdapter();
        
        try {
            $result = $adapter->execute(function() use ($adapter, $postId, $count, $offset) {
                return $adapter->wall()->getComments(
                    $adapter->getToken(),
                    [
                        'owner_id' => $this->ownerId,
                        'post_id' => $postId,
                        'offset' => $offset,
                        'count' => $count
                    ]
                );
            }, "getting comments for post {$postId}");
            
            // SDK returns array with 'items' key
            if (is_array($result) && isset($result['items'])) {
                // Convert array items to objects for backward compatibility
                // Recursively convert nested arrays to objects
                $items = $result['items'];
                return array_map(function($item) {
                    return $this->arrayToObject($item);
                }, $items);
            }
            
            return null;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
    
    /**
     * Get single comment
     * 
     * @param int $commentId Comment ID
     * @return object|array|null Returns comment object/array, or null on error
     */
    public function getComment(int $commentId)
    {
        $adapter = $this->getAdapter();
        
        try {
            $result = $adapter->execute(function() use ($adapter, $commentId) {
                return $adapter->wall()->getComment(
                    $adapter->getToken(),
                    [
                        'owner_id' => $this->ownerId,
                        'comment_id' => $commentId
                    ]
                );
            }, "getting comment {$commentId}");
            
            // SDK returns array of comments
            if (is_array($result) && !empty($result)) {
                $comment = $result[0];
                // Convert to object for backward compatibility
                // Recursively convert nested arrays to objects
                return $this->arrayToObject($comment);
            }
            
            return null;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
    
    /**
     * Pin post on the wall
     * 
     * @param int $postId Post ID to pin
     * @return int|mixed Returns result code or result data, or null on error
     */
    public function pinPost(int $postId)
    {
        sleep(1); // Rate limiting
        
        $adapter = $this->getAdapter();
        
        try {
            $result = $adapter->execute(function() use ($adapter, $postId) {
                return $adapter->wall()->pin(
                    $adapter->getToken(),
                    [
                        'post_id' => $postId,
                        'owner_id' => $this->ownerId
                    ]
                );
            }, "pinning post {$postId}");
            
            return $result;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
}

