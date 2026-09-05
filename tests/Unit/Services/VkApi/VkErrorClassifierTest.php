<?php

namespace Tests\Unit\Services\VkApi;

use App\Services\VkApi\VkErrorClassifier;
use App\Services\VkApi\VkRequestException;
use PHPUnit\Framework\TestCase;
use VK\Client\VKApiError;
use VK\Exceptions\Api\VKApiAccessException;
use VK\Exceptions\Api\VKApiFloodException;
use VK\Exceptions\Api\VKApiPrivateProfileException;
use VK\Exceptions\Api\VKApiRateLimitException;
use VK\Exceptions\Api\VKApiServerException;
use VK\Exceptions\Api\VKApiTooManyException;
use VK\Exceptions\Api\VKApiWeightedFloodException;
use VK\Exceptions\VKApiException;
use VK\Exceptions\VKClientException;

class VkErrorClassifierTest extends TestCase
{
    public function test_returns_existing_request_exception_as_is(): void
    {
        $original = new VkRequestException('kept', VkRequestException::CATEGORY_PRIVACY, 30);

        $classified = VkErrorClassifier::fromThrowable($original);

        $this->assertSame($original, $classified);
    }

    public function test_classifies_flood_exception(): void
    {
        $classified = VkErrorClassifier::fromThrowable($this->sdkApi(VKApiFloodException::class, 9, 'Flood control'));

        $this->assertSame(VkRequestException::CATEGORY_FLOOD, $classified->category);
        $this->assertSame(9, $classified->vkCode);
        $this->assertFalse($classified->retryable);
        $this->assertTrue($classified->stopsRun);
        $this->assertFalse($classified->isPrivacy());
    }

    public function test_classifies_too_many_requests_as_retryable_rate_limit(): void
    {
        $classified = VkErrorClassifier::fromThrowable(
            $this->sdkApi(VKApiTooManyException::class, 6, 'Too many requests per second')
        );

        $this->assertSame(VkRequestException::CATEGORY_RATE_LIMIT, $classified->category);
        $this->assertSame(6, $classified->vkCode);
        $this->assertTrue($classified->retryable);
        $this->assertFalse($classified->stopsRun);
    }

    public function test_classifies_daily_rate_limit_as_stop_without_retry(): void
    {
        $classified = VkErrorClassifier::fromThrowable(
            $this->sdkApi(VKApiRateLimitException::class, 29, 'Rate limit reached')
        );

        $this->assertSame(VkRequestException::CATEGORY_RATE_LIMIT, $classified->category);
        $this->assertSame(29, $classified->vkCode);
        $this->assertFalse($classified->retryable);
        $this->assertTrue($classified->stopsRun);
    }

    public function test_classifies_weighted_flood_as_flood_stop(): void
    {
        $classified = VkErrorClassifier::fromThrowable(
            $this->sdkApi(VKApiWeightedFloodException::class, 601, 'You have requested too many actions this day')
        );

        $this->assertSame(VkRequestException::CATEGORY_FLOOD, $classified->category);
        $this->assertSame(601, $classified->vkCode);
        $this->assertFalse($classified->retryable);
        $this->assertTrue($classified->stopsRun);
    }

    public function test_classifies_private_profile(): void
    {
        $classified = VkErrorClassifier::fromThrowable(
            $this->sdkApi(VKApiPrivateProfileException::class, 30, 'This profile is private')
        );

        $this->assertSame(VkRequestException::CATEGORY_PRIVACY, $classified->category);
        $this->assertSame(30, $classified->vkCode);
        $this->assertFalse($classified->retryable);
        $this->assertFalse($classified->stopsRun);
        $this->assertTrue($classified->isPrivacy());
    }

    public function test_classifies_access_denied(): void
    {
        $classified = VkErrorClassifier::fromThrowable(
            $this->sdkApi(VKApiAccessException::class, 15, 'Access denied')
        );

        $this->assertSame(VkRequestException::CATEGORY_ACCESS, $classified->category);
        $this->assertSame(15, $classified->vkCode);
        $this->assertTrue($classified->isPrivacy());
        $this->assertFalse($classified->stopsRun);
    }

    public function test_classifies_server_error_as_retryable_api(): void
    {
        $classified = VkErrorClassifier::fromThrowable(
            $this->sdkApi(VKApiServerException::class, 10, 'Internal server error')
        );

        $this->assertSame(VkRequestException::CATEGORY_API, $classified->category);
        $this->assertSame(10, $classified->vkCode);
        $this->assertTrue($classified->retryable);
        $this->assertFalse($classified->stopsRun);
    }

    public function test_classifies_generic_api_exception_code_10_as_retryable(): void
    {
        $error = new VKApiError(['error_code' => 10, 'error_msg' => 'Internal server error']);
        $classified = VkErrorClassifier::fromThrowable(new VKApiException(10, 'Server error', $error));

        $this->assertSame(VkRequestException::CATEGORY_API, $classified->category);
        $this->assertSame(10, $classified->vkCode);
        $this->assertTrue($classified->retryable);
        $this->assertFalse($classified->stopsRun);
    }

    public function test_classifies_generic_api_exception_by_code(): void
    {
        $error = new VKApiError(['error_code' => 1, 'error_msg' => 'Unknown error']);
        $classified = VkErrorClassifier::fromThrowable(new VKApiException(1, 'Unknown error', $error));

        $this->assertSame(VkRequestException::CATEGORY_API, $classified->category);
        $this->assertSame(1, $classified->vkCode);
        $this->assertFalse($classified->retryable);
        $this->assertFalse($classified->stopsRun);
    }

    public function test_classifies_client_exception_as_retryable_transport(): void
    {
        $classified = VkErrorClassifier::fromThrowable(new VKClientException('Connection timed out'));

        $this->assertSame(VkRequestException::CATEGORY_TRANSPORT, $classified->category);
        $this->assertTrue($classified->retryable);
        $this->assertFalse($classified->stopsRun);
        $this->assertSame('Connection timed out', $classified->getMessage());
    }

    public function test_sanitizes_token_and_does_not_echo_request_url(): void
    {
        $url = 'https://api.vk.com/method/friends.get?access_token=vk1.secret&user_id=1';
        $classified = VkErrorClassifier::fromThrowable(
            new VKClientException('HTTP error for '.$url)
        );

        $this->assertSame(VkRequestException::CATEGORY_TRANSPORT, $classified->category);
        $this->assertStringNotContainsString('vk1.secret', $classified->getMessage());
        $this->assertStringNotContainsString('access_token=', $classified->getMessage());
        $this->assertStringNotContainsString($url, $classified->getMessage());
        $this->assertSame('VK transport error', $classified->getMessage());
    }

    public function test_sanitize_message_redacts_token_and_query(): void
    {
        $inQuery = 'fail https://api.vk.com/method/users.get?access_token=tok&client_secret=sec';
        $cleanUrl = VkErrorClassifier::sanitizeMessage($inQuery);

        $this->assertStringNotContainsString('tok', $cleanUrl);
        $this->assertStringNotContainsString('sec', $cleanUrl);
        $this->assertStringContainsString('https://api.vk.com/method/users.get?[redacted]', $cleanUrl);

        $inline = 'access_token=tok client_secret=sec';
        $cleanInline = VkErrorClassifier::sanitizeMessage($inline);
        $this->assertStringContainsString('access_token=[redacted]', $cleanInline);
        $this->assertStringContainsString('client_secret=[redacted]', $cleanInline);
    }

    /**
     * @param class-string $class
     */
    private function sdkApi(string $class, int $code, string $message): VKApiException
    {
        $error = new VKApiError([
            'error_code' => $code,
            'error_msg' => $message,
        ]);

        return new $class($error);
    }
}
