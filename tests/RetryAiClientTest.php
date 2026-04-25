<?php

declare(strict_types=1);

namespace Tests\Ai;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Response\TokenUsage;
use EzPhp\Ai\RetryAiClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Ai\Support\CountingFailClient;

#[CoversClass(RetryAiClient::class)]
#[UsesClass(AiRequest::class)]
#[UsesClass(AiResponse::class)]
#[UsesClass(AiRequestException::class)]
#[UsesClass(FinishReason::class)]
#[UsesClass(TokenUsage::class)]
final class RetryAiClientTest extends TestCase
{
    private function makeResponse(string $content = 'OK'): AiResponse
    {
        return new AiResponse($content, FinishReason::STOP, new TokenUsage(5, 5), '{}');
    }

    private function makeRequest(): AiRequest
    {
        return AiRequest::make('Hello');
    }

    private function alwaysSucceedClient(AiResponse $response): AiClientInterface
    {
        return new class ($response) implements AiClientInterface {
            public function __construct(private readonly AiResponse $response)
            {
            }

            public function complete(AiRequest $request): AiResponse
            {
                return $this->response;
            }
        };
    }

    private function alwaysFailClient(int $statusCode, string $body = '{}'): AiClientInterface
    {
        return new class ($statusCode, $body) implements AiClientInterface {
            public function __construct(
                private readonly int $statusCode,
                private readonly string $body,
            ) {
            }

            public function complete(AiRequest $request): AiResponse
            {
                throw AiRequestException::fromResponse($this->statusCode, $this->body);
            }
        };
    }

    public function testSuccessOnFirstAttemptReturnsResponseImmediately(): void
    {
        $response = $this->makeResponse();
        $client = new RetryAiClient($this->alwaysSucceedClient($response), maxAttempts: 3, baseDelayMs: 0);

        self::assertSame($response, $client->complete($this->makeRequest()));
    }

    public function testRetriesOnRateLimitAndEventuallySucceeds(): void
    {
        $response = $this->makeResponse();
        $inner = new CountingFailClient(2, 429, $response);
        $client = new RetryAiClient($inner, maxAttempts: 3, baseDelayMs: 0);

        $result = $client->complete($this->makeRequest());

        self::assertSame($response, $result);
        self::assertSame(3, $inner->callCount());
    }

    public function testRetriesOnServerErrorAndEventuallySucceeds(): void
    {
        $response = $this->makeResponse();
        $inner = new CountingFailClient(1, 503, $response);
        $client = new RetryAiClient($inner, maxAttempts: 3, baseDelayMs: 0);

        $client->complete($this->makeRequest());

        self::assertSame(2, $inner->callCount());
    }

    public function testDoesNotRetryOnNonRetryable4xx(): void
    {
        $inner = new CountingFailClient(99, 400, $this->makeResponse());
        $client = new RetryAiClient($inner, maxAttempts: 3, baseDelayMs: 0);

        $this->expectException(AiRequestException::class);
        $client->complete($this->makeRequest());
    }

    public function testNonRetryable4xxAbortedOnFirstAttempt(): void
    {
        $inner = new CountingFailClient(99, 400, $this->makeResponse());
        $client = new RetryAiClient($inner, maxAttempts: 3, baseDelayMs: 0);

        try {
            $client->complete($this->makeRequest());
        } catch (AiRequestException) {
        }

        self::assertSame(1, $inner->callCount());
    }

    public function testDoesNotRetryOn401(): void
    {
        $client = new RetryAiClient($this->alwaysFailClient(401), maxAttempts: 3, baseDelayMs: 0);

        $this->expectException(AiRequestException::class);
        $client->complete($this->makeRequest());
    }

    public function testThrowsAfterAllAttemptsExhausted(): void
    {
        $client = new RetryAiClient($this->alwaysFailClient(429), maxAttempts: 2, baseDelayMs: 0);

        $this->expectException(AiRequestException::class);
        $client->complete($this->makeRequest());
    }

    public function testExhaustsAllAttemptsBeforeThrowing(): void
    {
        $inner = new CountingFailClient(99, 429, $this->makeResponse());
        $client = new RetryAiClient($inner, maxAttempts: 3, baseDelayMs: 0);

        try {
            $client->complete($this->makeRequest());
        } catch (AiRequestException) {
        }

        self::assertSame(3, $inner->callCount());
    }

    public function testParsesRetryAfterFromResponseBodyAndSucceeds(): void
    {
        $response = $this->makeResponse();
        $inner = new CountingFailClient(1, 429, $response, '{"retry_after":0}');
        $client = new RetryAiClient($inner, maxAttempts: 2, baseDelayMs: 0);

        $result = $client->complete($this->makeRequest());

        self::assertSame($response, $result);
    }

    public function testConstructorThrowsOnZeroMaxAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryAiClient($this->alwaysSucceedClient($this->makeResponse()), maxAttempts: 0);
    }

    public function testConstructorThrowsOnNegativeBaseDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryAiClient($this->alwaysSucceedClient($this->makeResponse()), baseDelayMs: -1);
    }
}
