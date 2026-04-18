<?php

declare(strict_types=1);

namespace Tests\Ai\Response;

use EzPhp\Ai\Response\FinishReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Ai\TestCase;

#[CoversClass(FinishReason::class)]
final class FinishReasonTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $names = array_map(static fn (FinishReason $r) => $r->name, FinishReason::cases());

        self::assertContains('STOP', $names);
        self::assertContains('LENGTH', $names);
        self::assertContains('TOOL_CALL', $names);
        self::assertContains('CONTENT_FILTER', $names);
        self::assertContains('ERROR', $names);
        self::assertCount(5, FinishReason::cases());
    }

    /**
     * @param string $name
     * @param string $value
     */
    #[DataProvider('reasonProvider')]
    public function testBackingValues(string $name, string $value): void
    {
        $reason = FinishReason::from($value);

        self::assertSame($name, $reason->name);
        self::assertSame($value, $reason->value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function reasonProvider(): array
    {
        return [
            'stop' => ['STOP', 'stop'],
            'length' => ['LENGTH', 'length'],
            'tool_call' => ['TOOL_CALL', 'tool_call'],
            'content_filter' => ['CONTENT_FILTER', 'content_filter'],
            'error' => ['ERROR', 'error'],
        ];
    }
}
