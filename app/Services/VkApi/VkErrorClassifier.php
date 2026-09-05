<?php

namespace App\Services\VkApi;

use Throwable;
use VK\Exceptions\Api\VKApiAccessException;
use VK\Exceptions\Api\VKApiFloodException;
use VK\Exceptions\Api\VKApiPrivateProfileException;
use VK\Exceptions\Api\VKApiRateLimitException;
use VK\Exceptions\Api\VKApiServerException;
use VK\Exceptions\Api\VKApiTooManyException;
use VK\Exceptions\Api\VKApiWeightedFloodException;
use VK\Exceptions\VKApiException;
use VK\Exceptions\VKClientException;

class VkErrorClassifier
{
    public static function fromThrowable(Throwable $e): VkRequestException
    {
        if ($e instanceof VkRequestException) {
            return $e;
        }

        $vkCode = self::vkCode($e);
        $classified = self::classify($e, $vkCode);
        $message = self::buildMessage($e, $classified['category']);

        return new VkRequestException(
            $message,
            $classified['category'],
            $vkCode,
            $classified['retryable'],
            $classified['stopsRun'],
            $e
        );
    }

    /**
     * @return array{category:string,retryable:bool,stopsRun:bool}
     */
    private static function classify(Throwable $e, ?int $vkCode): array
    {
        if ($e instanceof VKApiFloodException || $vkCode === 9) {
            return self::spec(VkRequestException::CATEGORY_FLOOD, false, true);
        }

        if ($e instanceof VKApiWeightedFloodException || $vkCode === 601) {
            return self::spec(VkRequestException::CATEGORY_FLOOD, false, true);
        }

        if ($e instanceof VKApiTooManyException || $vkCode === 6) {
            return self::spec(VkRequestException::CATEGORY_RATE_LIMIT, true, false);
        }

        if ($e instanceof VKApiRateLimitException || $vkCode === 29) {
            return self::spec(VkRequestException::CATEGORY_RATE_LIMIT, false, true);
        }

        if ($e instanceof VKApiPrivateProfileException || $vkCode === 30 || $vkCode === 18) {
            return self::spec(VkRequestException::CATEGORY_PRIVACY, false, false);
        }

        if ($e instanceof VKApiAccessException || $vkCode === 15) {
            return self::spec(VkRequestException::CATEGORY_ACCESS, false, false);
        }

        if ($e instanceof VKApiServerException || $vkCode === 10) {
            return self::spec(VkRequestException::CATEGORY_API, true, false);
        }

        if ($e instanceof VKClientException) {
            return self::spec(VkRequestException::CATEGORY_TRANSPORT, true, false);
        }

        if ($e instanceof VKApiException) {
            return self::spec(VkRequestException::CATEGORY_API, false, false);
        }

        return self::spec(VkRequestException::CATEGORY_API, false, false);
    }

    /**
     * @return array{category:string,retryable:bool,stopsRun:bool}
     */
    private static function spec(string $category, bool $retryable, bool $stopsRun): array
    {
        return [
            'category' => $category,
            'retryable' => $retryable,
            'stopsRun' => $stopsRun,
        ];
    }

    private static function vkCode(Throwable $e): ?int
    {
        if ($e instanceof VKApiException) {
            return $e->getErrorCode();
        }

        $code = $e->getCode();

        return $code !== 0 ? (int) $code : null;
    }

    private static function buildMessage(Throwable $e, string $category): string
    {
        $raw = $e->getMessage();
        $sanitized = self::sanitizeMessage($raw);

        if (self::looksLikeRequestUrl($raw)) {
            return sprintf('VK %s error', $category);
        }

        return $sanitized !== '' ? $sanitized : sprintf('VK %s error', $category);
    }

    public static function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/access_token=[^&\s]+/i', 'access_token=[redacted]', $message) ?? $message;
        $message = preg_replace('/client_secret=[^&\s]+/i', 'client_secret=[redacted]', $message) ?? $message;
        $message = preg_replace('#(https?://[^\s?]+)(\?[^\s]*)#i', '$1?[redacted]', $message) ?? $message;

        return $message;
    }

    private static function looksLikeRequestUrl(string $message): bool
    {
        return (bool) preg_match('#https?://#i', $message);
    }
}
