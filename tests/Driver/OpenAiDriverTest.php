<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\OpenAiConfig;
use EzPhp\Ai\Driver\OpenAiDriver;
use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Message\ContentPart;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\OpenAiDriver
 * @covers \EzPhp\Ai\Driver\OpenAiConfig
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
final class OpenAiDriverTest extends TestCase
{
    private function makeDriver(FakeTransport $transport, ?OpenAiConfig $config = null): OpenAiDriver
    {
        return new OpenAiDriver(
            new HttpClient($transport),
            $config ?? new OpenAiConfig('test-key'),
        );
    }

    /**
     * Decode the body of the first recorded request as an array.
     *
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

    private function successBody(
        string $content = 'Hello!',
        string $finishReason = 'stop',
        int $promptTokens = 10,
        int $completionTokens = 5,
    ): string {
        return (string) json_encode([
            'choices' => [
                ['message' => ['content' => $content], 'finish_reason' => $finishReason],
            ],
            'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
        ]);
    }

    // ─── Interface ───────────────────────────────────────────────────────────

    public function testImplementsAiClientInterface(): void
    {
        $driver = $this->makeDriver(new FakeTransport());
        $this->assertInstanceOf(AiClientInterface::class, $driver);
    }

    // ─── Happy path ──────────────────────────────────────────────────────────

    public function testReturnsAiResponseOnSuccess(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody('Hi there!'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('ping'));

        $this->assertSame('Hi there!', $response->content());
        $this->assertSame(FinishReason::STOP, $response->finishReason());
    }

    public function testTokenUsageIsMapped(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(promptTokens: 42, completionTokens: 17))]);
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
        $this->assertCount(1, $recorded);
        $this->assertSame('POST', $recorded[0]['method']);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $recorded[0]['url']);
    }

    public function testSendsAuthorizationHeader(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport, new OpenAiConfig('sk-secret'))->complete(AiRequest::make('hi'));

        $headers = $transport->getRecorded()[0]['headers'];
        $this->assertSame('Bearer sk-secret', $headers['Authorization']);
    }

    public function testUsesRequestModelWhenSet(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withModel('gpt-4o'));

        $body = $this->recordedBody($transport);
        $this->assertSame('gpt-4o', $body['model']);
    }

    public function testFallsBackToConfigModelWhenRequestHasNone(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $config = new OpenAiConfig('key', 'gpt-4-turbo');
        $this->makeDriver($transport, $config)->complete(AiRequest::make('hi'));

        $body = $this->recordedBody($transport);
        $this->assertSame('gpt-4-turbo', $body['model']);
    }

    public function testTemperatureAndMaxTokensAreSentWhenSet(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $request = AiRequest::make('hi')->withTemperature(0.5)->withMaxTokens(200);
        $this->makeDriver($transport)->complete($request);

        $body = $this->recordedBody($transport);
        $this->assertSame(0.5, $body['temperature']);
        $this->assertSame(200, $body['max_tokens']);
    }

    public function testOptionalFieldsOmittedWhenNotSet(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $body = $this->recordedBody($transport);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertArrayNotHasKey('max_tokens', $body);
    }

    public function testSystemPromptPrependedAsSystemMessage(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $request = AiRequest::make('hello')->withSystemPrompt('Be concise.');
        $this->makeDriver($transport)->complete($request);

        $body = $this->recordedBody($transport);
        $this->assertIsArray($body['messages']);
        /** @var list<array<string, mixed>> $messages */
        $messages = $body['messages'];
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('Be concise.', $messages[0]['content']);
        $this->assertSame('user', $messages[1]['role']);
    }

    public function testMultipleMessagesSerialised(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $request = AiRequest::withMessages(
            AiMessage::user('Hello'),
            AiMessage::assistant('Hi!'),
            AiMessage::user('How are you?'),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $this->recordedBody($transport);
        $this->assertIsArray($body['messages']);
        /** @var list<array<string, mixed>> $messages */
        $messages = $body['messages'];
        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('user', $messages[2]['role']);
    }

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
        $this->assertSame('image_url', $parts[1]['type']);
        $this->assertIsArray($parts[1]['image_url']);
        $this->assertSame('https://example.com/img.jpg', $parts[1]['image_url']['url']);
    }

    // ─── Finish reason mapping ────────────────────────────────────────────────

    public function testFinishReasonLengthMapped(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(finishReason: 'length'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::LENGTH, $response->finishReason());
    }

    public function testFinishReasonToolCallsMapped(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(finishReason: 'tool_calls'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::TOOL_CALL, $response->finishReason());
    }

    public function testFinishReasonContentFilterMapped(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(finishReason: 'content_filter'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::CONTENT_FILTER, $response->finishReason());
    }

    public function testUnknownFinishReasonMapsToError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(finishReason: 'unknown_value'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::ERROR, $response->finishReason());
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testThrowsAiRequestExceptionOn4xx(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(401, '{"error":{"message":"Invalid API key"}}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testThrowsAiRequestExceptionOn5xx(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(500, 'Internal Server Error')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testExceptionCarriesStatusCodeAndBody(): void
    {
        $errorBody = '{"error":{"message":"Quota exceeded"}}';
        $transport = new FakeTransport(['*' => new HttpResponse(429, $errorBody)]);

        try {
            $this->makeDriver($transport)->complete(AiRequest::make('hi'));
            $this->fail('Expected AiRequestException');
        } catch (AiRequestException $e) {
            $this->assertSame(429, $e->statusCode());
            $this->assertSame($errorBody, $e->responseBody());
        }
    }

    public function testThrowsOnNonJsonResponseBody(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, 'not json at all')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testThrowsWhenChoicesAreMissing(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, '{"usage":{"prompt_tokens":1,"completion_tokens":1}}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testThrowsWhenMessageContentIsMissing(): void
    {
        $body = (string) json_encode(['choices' => [['message' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    // ─── Config ───────────────────────────────────────────────────────────────

    public function testConfigDefaultModel(): void
    {
        $config = new OpenAiConfig('key');
        $this->assertSame('gpt-4o-mini', $config->model());
    }

    public function testConfigDefaultBaseUrl(): void
    {
        $config = new OpenAiConfig('key');
        $this->assertSame('https://api.openai.com', $config->baseUrl());
    }

    public function testConfigCustomBaseUrlStripsTrailingSlash(): void
    {
        $config = new OpenAiConfig('key', 'gpt-4o', 'https://my-proxy.example.com/');
        $this->assertSame('https://my-proxy.example.com', $config->baseUrl());
    }

    public function testCustomBaseUrlUsedInRequest(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $config = new OpenAiConfig('key', 'gpt-4o', 'https://proxy.example.com');
        $this->makeDriver($transport, $config)->complete(AiRequest::make('hi'));

        $this->assertSame('https://proxy.example.com/v1/chat/completions', $transport->getRecorded()[0]['url']);
    }
}
