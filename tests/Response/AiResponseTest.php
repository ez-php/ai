<?php

declare(strict_types=1);

namespace Tests\Ai\Response;

use EzPhp\Ai\Response\AiResponse;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Response\TokenUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Ai\TestCase;

#[CoversClass(AiResponse::class)]
#[UsesClass(FinishReason::class)]
#[UsesClass(TokenUsage::class)]
final class AiResponseTest extends TestCase
{
    private function makeResponse(
        string $content = 'Hello!',
        FinishReason $finishReason = FinishReason::STOP,
        string $rawBody = '{}',
    ): AiResponse {
        return new AiResponse($content, $finishReason, new TokenUsage(10, 5), $rawBody);
    }

    public function testGetters(): void
    {
        $response = $this->makeResponse('The answer is 42.', FinishReason::STOP, '{"raw": true}');

        self::assertSame('The answer is 42.', $response->content());
        self::assertSame(FinishReason::STOP, $response->finishReason());
        self::assertSame('{"raw": true}', $response->rawBody());
        self::assertSame(10, $response->usage()->inputTokens());
        self::assertSame(5, $response->usage()->outputTokens());
    }

    public function testIsCompleteWhenStopReason(): void
    {
        $response = $this->makeResponse(finishReason: FinishReason::STOP);

        self::assertTrue($response->isComplete());
    }

    public function testIsNotCompleteForNonStopReasons(): void
    {
        foreach ([FinishReason::LENGTH, FinishReason::TOOL_CALL, FinishReason::CONTENT_FILTER, FinishReason::ERROR] as $reason) {
            $response = $this->makeResponse(finishReason: $reason);

            self::assertFalse($response->isComplete(), "Expected isComplete() = false for {$reason->value}");
        }
    }

    // ─── json ────────────────────────────────────────────────────────────────

    public function testJsonDecodesObject(): void
    {
        $response = $this->makeResponse('{"name":"sword","damage":10}');

        $data = $response->json();

        self::assertSame('sword', $data['name']);
        self::assertSame(10, $data['damage']);
    }

    public function testJsonThrowsOnJsonArray(): void
    {
        $this->expectException(\JsonException::class);
        $this->makeResponse('[1,2,3]')->json();
    }

    public function testJsonThrowsOnInvalidJson(): void
    {
        $this->expectException(\JsonException::class);
        $this->makeResponse('not json at all')->json();
    }

    public function testJsonStripsMarkdownCodeFences(): void
    {
        $content = "```json\n{\"key\":\"value\"}\n```";
        $response = $this->makeResponse($content);

        self::assertSame('value', $response->json()['key']);
    }

    // ─── jsonArray ───────────────────────────────────────────────────────────

    public function testJsonArrayDecodesList(): void
    {
        $response = $this->makeResponse('["apple","banana","cherry"]');

        $data = $response->jsonArray();

        self::assertSame(['apple', 'banana', 'cherry'], $data);
    }

    public function testJsonArrayThrowsOnJsonObject(): void
    {
        $this->expectException(\JsonException::class);
        $this->makeResponse('{"key":"value"}')->jsonArray();
    }

    public function testJsonArrayThrowsOnInvalidJson(): void
    {
        $this->expectException(\JsonException::class);
        $this->makeResponse('not json')->jsonArray();
    }

    public function testJsonArrayStripsMarkdownCodeFences(): void
    {
        $content = "```\n[\"a\",\"b\"]\n```";
        $response = $this->makeResponse($content);

        self::assertSame(['a', 'b'], $response->jsonArray());
    }
}
