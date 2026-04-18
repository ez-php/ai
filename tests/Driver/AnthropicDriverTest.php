<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\AnthropicConfig;
use EzPhp\Ai\Driver\AnthropicDriver;
use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Message\ContentPart;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\AnthropicDriver
 * @covers \EzPhp\Ai\Driver\AnthropicConfig
 * @uses   \EzPhp\Ai\Request\AiRequest
 * @uses   \EzPhp\Ai\Response\AiResponse
 * @uses   \EzPhp\Ai\Response\TokenUsage
 * @uses   \EzPhp\Ai\Response\FinishReason
 * @uses   \EzPhp\Ai\Message\AiMessage
 * @uses   \EzPhp\Ai\Message\Role
 * @uses   \EzPhp\Ai\Message\ContentPart
 * @uses   \EzPhp\Ai\Message\ContentPartType
 * @uses   \EzPhp\Ai\AiRequestException
 * @uses   \EzPhp\Ai\AiException
 */
final class AnthropicDriverTest extends TestCase
{
    private function makeDriver(FakeTransport $transport, ?AnthropicConfig $config = null): AnthropicDriver
    {
        return new AnthropicDriver(
            new HttpClient($transport),
            $config ?? new AnthropicConfig('test-key'),
        );
    }

    private function successBody(
        string $content = 'Hello!',
        string $stopReason = 'end_turn',
        int $inputTokens = 10,
        int $outputTokens = 5,
    ): string {
        return (string) json_encode([
            'content' => [['type' => 'text', 'text' => $content]],
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ]);
    }

    /**
     * @param FakeTransport $transport
     *
     * @return array<string, mixed>
     */
    private function recordedBody(FakeTransport $transport): array
    {
        $recorded = $transport->getRecorded();
        $this->assertNotEmpty($recorded, 'No requests were recorded');
        $decoded = json_decode($recorded[0]['body'], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    // ─── Interface ───────────────────────────────────────────────────────────

    public function testImplementsAiClientInterface(): void
    {
        $this->assertInstanceOf(AiClientInterface::class, $this->makeDriver(new FakeTransport()));
    }

    // ─── Happy path ──────────────────────────────────────────────────────────

    public function testReturnsAiResponseOnSuccess(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody('Hi!'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('ping'));

        $this->assertSame('Hi!', $response->content());
        $this->assertSame(FinishReason::STOP, $response->finishReason());
    }

    public function testTokenUsageIsMapped(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(inputTokens: 42, outputTokens: 17))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(42, $response->usage()->inputTokens());
        $this->assertSame(17, $response->usage()->outputTokens());
    }

    public function testRawBodyIsPreserved(): void
    {
        $raw = $this->successBody('raw');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $raw)]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('x'));

        $this->assertSame($raw, $response->rawBody());
    }

    // ─── Request serialisation ────────────────────────────────────────────────

    public function testSendsPostToCorrectUrl(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $recorded = $transport->getRecorded();
        $this->assertSame('POST', $recorded[0]['method']);
        $this->assertSame('https://api.anthropic.com/v1/messages', $recorded[0]['url']);
    }

    public function testSendsRequiredHeaders(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport, new AnthropicConfig('sk-secret', apiVersion: '2023-06-01'))
            ->complete(AiRequest::make('hi'));

        $headers = $transport->getRecorded()[0]['headers'];
        $this->assertSame('sk-secret', $headers['x-api-key']);
        $this->assertSame('2023-06-01', $headers['anthropic-version']);
    }

    public function testUsesRequestModelWhenSet(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withModel('claude-opus-4-7'));

        $body = $this->recordedBody($transport);
        $this->assertSame('claude-opus-4-7', $body['model']);
    }

    public function testFallsBackToConfigModelWhenRequestHasNone(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport, new AnthropicConfig('key', 'claude-haiku-4-5-20251001'))
            ->complete(AiRequest::make('hi'));

        $body = $this->recordedBody($transport);
        $this->assertSame('claude-haiku-4-5-20251001', $body['model']);
    }

    public function testMaxTokensUsedFromRequest(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withMaxTokens(512));

        $body = $this->recordedBody($transport);
        $this->assertSame(512, $body['max_tokens']);
    }

    public function testDefaultMaxTokensUsedWhenNotSet(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $body = $this->recordedBody($transport);
        $this->assertSame(1024, $body['max_tokens']);
    }

    public function testTemperatureIsSentWhenSet(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withTemperature(0.3));

        $body = $this->recordedBody($transport);
        $this->assertSame(0.3, $body['temperature']);
    }

    public function testTemperatureOmittedWhenNotSet(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $body = $this->recordedBody($transport);
        $this->assertArrayNotHasKey('temperature', $body);
    }

    // ─── System prompt handling ───────────────────────────────────────────────

    public function testSystemPromptPlacedAtTopLevel(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hello')->withSystemPrompt('Be concise.'));

        $body = $this->recordedBody($transport);
        $this->assertSame('Be concise.', $body['system']);
        $this->assertIsArray($body['messages']);
        /** @var list<array<string, mixed>> $messages */
        $messages = $body['messages'];
        $this->assertSame('user', $messages[0]['role']);
    }

    public function testSystemRoleMessageFilteredIntoSystemField(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $request = AiRequest::withMessages(
            AiMessage::system('You are a poet.'),
            AiMessage::user('Write a haiku.'),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $this->recordedBody($transport);
        $this->assertSame('You are a poet.', $body['system']);
        $this->assertIsArray($body['messages']);
        /** @var list<array<string, mixed>> $messages */
        $messages = $body['messages'];
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
    }

    public function testSystemPromptAndSystemRoleMessageAreMerged(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $request = AiRequest::withMessages(
            AiMessage::system('Part two.'),
            AiMessage::user('Hi'),
        )->withSystemPrompt('Part one.');
        $this->makeDriver($transport)->complete($request);

        $body = $this->recordedBody($transport);
        $this->assertSame("Part one.\n\nPart two.", $body['system']);
    }

    public function testSystemFieldAbsentWhenNoSystemContent(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $body = $this->recordedBody($transport);
        $this->assertArrayNotHasKey('system', $body);
    }

    // ─── Multimodal ───────────────────────────────────────────────────────────

    public function testMultimodalMessageSerialised(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $request = AiRequest::withMessages(
            AiMessage::userWithParts([ContentPart::text('Describe:'), ContentPart::imageUrl('https://example.com/img.jpg')]),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $this->recordedBody($transport);
        $this->assertIsArray($body['messages']);
        /** @var list<array<string, mixed>> $messages */
        $messages = $body['messages'];
        $this->assertIsArray($messages[0]['content']);
        /** @var list<array<string, mixed>> $parts */
        $parts = $messages[0]['content'];
        $this->assertCount(2, $parts);
        $this->assertSame('text', $parts[0]['type']);
        $this->assertSame('Describe:', $parts[0]['text']);
        $this->assertSame('image', $parts[1]['type']);
        $this->assertIsArray($parts[1]['source']);
        $this->assertSame('url', $parts[1]['source']['type']);
        $this->assertSame('https://example.com/img.jpg', $parts[1]['source']['url']);
    }

    public function testExtractTextContentSkipsNonArrayBlocks(): void
    {
        $body = (string) json_encode([
            'content' => ['not-an-array', ['type' => 'text', 'text' => 'Found me']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame('Found me', $response->content());
    }

    // ─── Finish reason mapping ────────────────────────────────────────────────

    public function testStopSequenceMapsToStop(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(stopReason: 'stop_sequence'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::STOP, $response->finishReason());
    }

    public function testMaxTokensMapsToLength(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(stopReason: 'max_tokens'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::LENGTH, $response->finishReason());
    }

    public function testToolUseMapsToToolCall(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(stopReason: 'tool_use'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::TOOL_CALL, $response->finishReason());
    }

    public function testUnknownStopReasonMapsToError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(stopReason: 'unknown'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::ERROR, $response->finishReason());
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testThrowsAiRequestExceptionOn401(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(401, '{"type":"error","error":{"type":"authentication_error"}}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testThrowsAiRequestExceptionOn529(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(529, '{"type":"error","error":{"type":"overloaded_error"}}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testExceptionCarriesStatusCodeAndBody(): void
    {
        $errorBody = '{"type":"error","error":{"type":"rate_limit_error"}}';
        $transport = new FakeTransport(['*' => new HttpResponse(429, $errorBody)]);

        try {
            $this->makeDriver($transport)->complete(AiRequest::make('hi'));
            $this->fail('Expected AiRequestException');
        } catch (AiRequestException $e) {
            $this->assertSame(429, $e->statusCode());
            $this->assertSame($errorBody, $e->responseBody());
        }
    }

    public function testThrowsOnNonJsonBody(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, 'not json')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testThrowsWhenContentArrayMissing(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, '{"stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1}}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testToolUseResponseWithNoTextIsValid(): void
    {
        $body = (string) json_encode([
            'content' => [['type' => 'tool_use', 'id' => 'toolu_x', 'name' => 'fn', 'input' => []]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('toolu_x', $response->toolCalls()[0]->id());
    }

    // ─── Config ───────────────────────────────────────────────────────────────

    public function testConfigDefaults(): void
    {
        $config = new AnthropicConfig('key');
        $this->assertSame('claude-sonnet-4-6', $config->model());
        $this->assertSame('2023-06-01', $config->apiVersion());
    }
}
