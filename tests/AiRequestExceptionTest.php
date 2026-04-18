<?php

declare(strict_types=1);

namespace Tests\Ai;

use EzPhp\Ai\AiException;
use EzPhp\Ai\AiRequestException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(AiRequestException::class)]
#[UsesClass(AiException::class)]
final class AiRequestExceptionTest extends TestCase
{
    public function testExtendsAiException(): void
    {
        $exception = new AiRequestException('bad request', 400, '{"error":"bad"}');

        self::assertInstanceOf(AiException::class, $exception);
    }

    public function testGetters(): void
    {
        $exception = new AiRequestException('bad request', 400, '{"error":"bad"}');

        self::assertSame('bad request', $exception->getMessage());
        self::assertSame(400, $exception->statusCode());
        self::assertSame('{"error":"bad"}', $exception->responseBody());
    }

    public function testDefaultValues(): void
    {
        $exception = new AiRequestException('oops');

        self::assertSame(0, $exception->statusCode());
        self::assertSame('', $exception->responseBody());
        self::assertNull($exception->getPrevious());
    }

    public function testFromResponseFactory(): void
    {
        $exception = AiRequestException::fromResponse(429, '{"error":"rate limit"}');

        self::assertSame('AI provider returned HTTP 429', $exception->getMessage());
        self::assertSame(429, $exception->statusCode());
        self::assertSame('{"error":"rate limit"}', $exception->responseBody());
    }

    public function testFromResponseFactoryWithCustomMessage(): void
    {
        $exception = AiRequestException::fromResponse(503, '', 'Service unavailable');

        self::assertSame('Service unavailable', $exception->getMessage());
        self::assertSame(503, $exception->statusCode());
    }

    public function testPreviousExceptionIsChained(): void
    {
        $previous = new \RuntimeException('original');
        $exception = new AiRequestException('wrapped', 0, '', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
