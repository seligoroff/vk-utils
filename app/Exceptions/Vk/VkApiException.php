<?php

namespace App\Exceptions\Vk;

/**
 * VK API returned an error response (non-transport).
 *
 * The `reason` field classifies the error for retry decisions
 * and user-facing messages. Compare against the REASON_* constants,
 * not string literals.
 */
class VkApiException extends VkException
{
    public const REASON_RATE_LIMIT    = 'rate_limit';
    public const REASON_ACCESS_DENIED = 'access_denied';
    public const REASON_INVALID_TOKEN = 'invalid_token';
    public const REASON_OTHER         = 'other';

    private string $reason;
    private ?int $apiCode;

    public function __construct(
        string $message,
        string $reason = self::REASON_OTHER,
        ?int $apiCode = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->reason  = $reason;
        $this->apiCode = $apiCode;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getApiCode(): ?int
    {
        return $this->apiCode;
    }
}
