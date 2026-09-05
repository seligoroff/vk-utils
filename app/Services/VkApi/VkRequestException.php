<?php

namespace App\Services\VkApi;

class VkRequestException extends \Exception
{
    public const CATEGORY_API = 'api';
    public const CATEGORY_RATE_LIMIT = 'rate_limit';
    public const CATEGORY_FLOOD = 'flood';
    public const CATEGORY_ACCESS = 'access';
    public const CATEGORY_PRIVACY = 'privacy';
    public const CATEGORY_TRANSPORT = 'transport';
    public const CATEGORY_UNEXPECTED_RESPONSE = 'unexpected_response';

    public function __construct(
        string $message,
        public readonly string $category,
        public readonly ?int $vkCode = null,
        public readonly bool $retryable = false,
        public readonly bool $stopsRun = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $vkCode ?? 0, $previous);
    }

    public function isPrivacy(): bool
    {
        return in_array($this->category, [self::CATEGORY_PRIVACY, self::CATEGORY_ACCESS], true);
    }
}
