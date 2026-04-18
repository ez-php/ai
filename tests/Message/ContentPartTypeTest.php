<?php

declare(strict_types=1);

namespace Tests\Ai\Message;

use EzPhp\Ai\Message\ContentPartType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Ai\TestCase;

#[CoversClass(ContentPartType::class)]
final class ContentPartTypeTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $names = array_map(static fn (ContentPartType $t) => $t->name, ContentPartType::cases());

        self::assertContains('TEXT', $names);
        self::assertContains('IMAGE_URL', $names);
        self::assertCount(2, ContentPartType::cases());
    }

    /**
     * @param string $name
     * @param string $value
     */
    #[DataProvider('typeProvider')]
    public function testBackingValues(string $name, string $value): void
    {
        $type = ContentPartType::from($value);

        self::assertSame($name, $type->name);
        self::assertSame($value, $type->value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function typeProvider(): array
    {
        return [
            'text' => ['TEXT', 'text'],
            'image_url' => ['IMAGE_URL', 'image_url'],
        ];
    }
}
