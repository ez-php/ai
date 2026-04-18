<?php

declare(strict_types=1);

namespace Tests\Message;

use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Message\ContentPart;
use EzPhp\Ai\Message\ContentPartType;
use EzPhp\Ai\Message\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

#[CoversClass(AiMessage::class)]
#[UsesClass(Role::class)]
#[UsesClass(ContentPart::class)]
#[UsesClass(ContentPartType::class)]
final class AiMessageTest extends TestCase
{
    public function testUserFactory(): void
    {
        $msg = AiMessage::user('Hello');

        self::assertSame(Role::USER, $msg->role());
        self::assertSame('Hello', $msg->content());
        self::assertFalse($msg->isMultimodal());
    }

    public function testAssistantFactory(): void
    {
        $msg = AiMessage::assistant('Hi there');

        self::assertSame(Role::ASSISTANT, $msg->role());
        self::assertSame('Hi there', $msg->content());
    }

    public function testSystemFactory(): void
    {
        $msg = AiMessage::system('You are helpful.');

        self::assertSame(Role::SYSTEM, $msg->role());
        self::assertSame('You are helpful.', $msg->content());
    }

    public function testToolFactory(): void
    {
        $msg = AiMessage::tool('{"result": 42}');

        self::assertSame(Role::TOOL, $msg->role());
        self::assertSame('{"result": 42}', $msg->content());
    }

    public function testMakeFactory(): void
    {
        $msg = AiMessage::make(Role::USER, 'Custom');

        self::assertSame(Role::USER, $msg->role());
        self::assertSame('Custom', $msg->content());
    }

    public function testUserWithParts(): void
    {
        $parts = [
            ContentPart::text('Describe this image:'),
            ContentPart::imageUrl('https://example.com/cat.jpg'),
        ];

        $msg = AiMessage::userWithParts($parts);

        self::assertSame(Role::USER, $msg->role());
        self::assertTrue($msg->isMultimodal());
        self::assertSame($parts, $msg->content());
    }

    public function testTextContentForPlainString(): void
    {
        $msg = AiMessage::user('Hello world');

        self::assertSame('Hello world', $msg->textContent());
    }

    public function testTextContentForMultimodalConcatenatesTextParts(): void
    {
        $parts = [
            ContentPart::text('First '),
            ContentPart::imageUrl('https://example.com/image.png'),
            ContentPart::text('Second'),
        ];

        $msg = AiMessage::userWithParts($parts);

        self::assertSame('First Second', $msg->textContent());
    }

    public function testTextContentForMultimodalWithNoTextPartsIsEmpty(): void
    {
        $parts = [ContentPart::imageUrl('https://example.com/image.png')];

        $msg = AiMessage::userWithParts($parts);

        self::assertSame('', $msg->textContent());
    }

    public function testMakeFactoryWithParts(): void
    {
        $parts = [ContentPart::text('hi')];

        $msg = AiMessage::make(Role::USER, $parts);

        self::assertTrue($msg->isMultimodal());
    }
}
