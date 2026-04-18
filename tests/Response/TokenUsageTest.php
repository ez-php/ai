<?php

declare(strict_types=1);

namespace Tests\Response;

use EzPhp\Ai\Response\TokenUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(TokenUsage::class)]
final class TokenUsageTest extends TestCase
{
    public function testGetters(): void
    {
        $usage = new TokenUsage(100, 50);

        self::assertSame(100, $usage->inputTokens());
        self::assertSame(50, $usage->outputTokens());
    }

    public function testTotalTokens(): void
    {
        $usage = new TokenUsage(100, 50);

        self::assertSame(150, $usage->totalTokens());
    }

    public function testZeroTokens(): void
    {
        $usage = new TokenUsage(0, 0);

        self::assertSame(0, $usage->inputTokens());
        self::assertSame(0, $usage->outputTokens());
        self::assertSame(0, $usage->totalTokens());
    }
}
