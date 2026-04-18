<?php

declare(strict_types=1);

namespace Tests\Request;

use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Message\Role;
use EzPhp\Ai\Request\AiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

#[CoversClass(AiRequest::class)]
#[UsesClass(AiMessage::class)]
#[UsesClass(Role::class)]
final class AiRequestTest extends TestCase
{
    public function testMakeCreatesRequestWithSingleUserMessage(): void
    {
        $request = AiRequest::make('Tell me a joke.');

        self::assertCount(1, $request->messages());
        self::assertSame(Role::USER, $request->messages()[0]->role());
        self::assertSame('Tell me a joke.', $request->messages()[0]->content());
        self::assertNull($request->model());
        self::assertNull($request->temperature());
        self::assertNull($request->maxTokens());
        self::assertNull($request->systemPrompt());
    }

    public function testWithMessagesFactory(): void
    {
        $request = AiRequest::withMessages(
            AiMessage::system('Be concise.'),
            AiMessage::user('Hello'),
        );

        self::assertCount(2, $request->messages());
        self::assertSame(Role::SYSTEM, $request->messages()[0]->role());
        self::assertSame(Role::USER, $request->messages()[1]->role());
    }

    public function testWithModelReturnsNewInstance(): void
    {
        $original = AiRequest::make('Hi');
        $modified = $original->withModel('gpt-4o');

        self::assertNull($original->model());
        self::assertSame('gpt-4o', $modified->model());
        self::assertNotSame($original, $modified);
    }

    public function testWithTemperatureReturnsNewInstance(): void
    {
        $original = AiRequest::make('Hi');
        $modified = $original->withTemperature(0.7);

        self::assertNull($original->temperature());
        self::assertSame(0.7, $modified->temperature());
        self::assertNotSame($original, $modified);
    }

    public function testWithMaxTokensReturnsNewInstance(): void
    {
        $original = AiRequest::make('Hi');
        $modified = $original->withMaxTokens(512);

        self::assertNull($original->maxTokens());
        self::assertSame(512, $modified->maxTokens());
        self::assertNotSame($original, $modified);
    }

    public function testWithSystemPromptReturnsNewInstance(): void
    {
        $original = AiRequest::make('Hi');
        $modified = $original->withSystemPrompt('You are a helpful assistant.');

        self::assertNull($original->systemPrompt());
        self::assertSame('You are a helpful assistant.', $modified->systemPrompt());
        self::assertNotSame($original, $modified);
    }

    public function testAddMessageAppendsAndReturnsNewInstance(): void
    {
        $original = AiRequest::make('Hello');
        $modified = $original->addMessage(AiMessage::assistant('Hi!'));

        self::assertCount(1, $original->messages());
        self::assertCount(2, $modified->messages());
        self::assertSame(Role::ASSISTANT, $modified->messages()[1]->role());
    }

    public function testFluentChaining(): void
    {
        $request = AiRequest::make('Explain quantum computing.')
            ->withModel('claude-sonnet-4-6')
            ->withTemperature(0.5)
            ->withMaxTokens(1024)
            ->withSystemPrompt('Be concise.');

        self::assertSame('claude-sonnet-4-6', $request->model());
        self::assertSame(0.5, $request->temperature());
        self::assertSame(1024, $request->maxTokens());
        self::assertSame('Be concise.', $request->systemPrompt());
    }

    public function testHasUserMessageReturnsTrueForUserMessage(): void
    {
        $request = AiRequest::make('Hello');

        self::assertTrue($request->hasUserMessage());
    }

    public function testHasUserMessageReturnsFalseForSystemOnly(): void
    {
        $request = AiRequest::withMessages(AiMessage::system('You are helpful.'));

        self::assertFalse($request->hasUserMessage());
    }

    public function testOriginalUnchangedAfterChaining(): void
    {
        $original = AiRequest::make('Hi');

        $original->withModel('gpt-4o')
            ->withTemperature(1.0)
            ->withMaxTokens(100)
            ->withSystemPrompt('test')
            ->addMessage(AiMessage::assistant('ok'));

        self::assertNull($original->model());
        self::assertNull($original->temperature());
        self::assertNull($original->maxTokens());
        self::assertNull($original->systemPrompt());
        self::assertCount(1, $original->messages());
    }
}
