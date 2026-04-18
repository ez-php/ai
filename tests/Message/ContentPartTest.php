<?php

declare(strict_types=1);

namespace Tests\Message;

use EzPhp\Ai\Message\ContentPart;
use EzPhp\Ai\Message\ContentPartType;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ContentPart::class)]
final class ContentPartTest extends TestCase
{
    public function testTextFactory(): void
    {
        $part = ContentPart::text('Hello world');

        self::assertSame(ContentPartType::TEXT, $part->type());
        self::assertSame('Hello world', $part->content());
    }

    public function testImageUrlFactory(): void
    {
        $part = ContentPart::imageUrl('https://example.com/image.png');

        self::assertSame(ContentPartType::IMAGE_URL, $part->type());
        self::assertSame('https://example.com/image.png', $part->content());
    }

    public function testIsImmutable(): void
    {
        $part = ContentPart::text('original');
        $clone = ContentPart::text('copy');

        self::assertSame('original', $part->content());
        self::assertSame('copy', $clone->content());
        self::assertNotSame($part, $clone);
    }
}
