<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\MistralConfig;
use EzPhp\Ai\Driver\MistralDriver;
use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\MistralDriver
 * @covers \EzPhp\Ai\Driver\MistralConfig
 * @uses   \EzPhp\Ai\Driver\OpenAiDriver
 * @uses   \EzPhp\Ai\Driver\OpenAiConfig
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
final class MistralDriverTest extends TestCase
{
    private function makeDriver(FakeTransport $transport, ?MistralConfig $config = null): MistralDriver
    {
        return new MistralDriver(
            new HttpClient($transport),
            $config ?? new MistralConfig('test-key'),
        );
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
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody('Bonjour!'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('ping'));

        $this->assertSame('Bonjour!', $response->content());
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

        $this->assertSame($raw, $this->makeDriver($transport)->complete(AiRequest::make('x'))->rawBody());
    }

    // ─── Request serialisation ────────────────────────────────────────────────

    public function testSendsPostToMistralChatCompletionsEndpoint(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $recorded = $transport->getRecorded();
        $this->assertSame('POST', $recorded[0]['method']);
        $this->assertSame('https://api.mistral.ai/v1/chat/completions', $recorded[0]['url']);
    }

    public function testApiKeyInBearerHeader(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport, new MistralConfig('sk-mistral-secret'))->complete(AiRequest::make('hi'));

        $this->assertSame('Bearer sk-mistral-secret', $transport->getRecorded()[0]['headers']['Authorization']);
    }

    public function testCustomBaseUrlUsedInRequest(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $config = new MistralConfig('key', 'mistral-small-latest', 'https://my-mistral-proxy.example.com');
        $this->makeDriver($transport, $config)->complete(AiRequest::make('hi'));

        $this->assertSame(
            'https://my-mistral-proxy.example.com/v1/chat/completions',
            $transport->getRecorded()[0]['url'],
        );
    }

    public function testUsesRequestModelWhenSet(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withModel('mistral-large-latest'));

        $body = $this->recordedBody($transport);
        $this->assertSame('mistral-large-latest', $body['model']);
    }

    public function testFallsBackToConfigModel(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport, new MistralConfig('key', 'open-mistral-nemo'))->complete(AiRequest::make('hi'));

        $body = $this->recordedBody($transport);
        $this->assertSame('open-mistral-nemo', $body['model']);
    }

    public function testTemperatureAndMaxTokensForwarded(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withTemperature(0.4)->withMaxTokens(512));

        $body = $this->recordedBody($transport);
        $this->assertSame(0.4, $body['temperature']);
        $this->assertSame(512, $body['max_tokens']);
    }

    public function testMultipleMessagesForwarded(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $request = AiRequest::withMessages(
            AiMessage::user('Hello'),
            AiMessage::assistant('Hi!'),
            AiMessage::user('Continue.'),
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

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testThrowsAiRequestExceptionOn4xx(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(401, '{"message":"Unauthorized"}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testThrowsAiRequestExceptionOn5xx(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(500, 'Service Unavailable')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testExceptionCarriesStatusCodeAndBody(): void
    {
        $errorBody = '{"message":"Rate limit exceeded"}';
        $transport = new FakeTransport(['*' => new HttpResponse(429, $errorBody)]);

        try {
            $this->makeDriver($transport)->complete(AiRequest::make('hi'));
            $this->fail('Expected AiRequestException');
        } catch (AiRequestException $e) {
            $this->assertSame(429, $e->statusCode());
            $this->assertSame($errorBody, $e->responseBody());
        }
    }

    // ─── Config ───────────────────────────────────────────────────────────────

    public function testConfigDefaults(): void
    {
        $config = new MistralConfig('key');
        $this->assertSame('mistral-small-latest', $config->model());
        $this->assertSame('https://api.mistral.ai', $config->baseUrl());
    }

    public function testConfigCustomBaseUrlStripsTrailingSlash(): void
    {
        $config = new MistralConfig('key', 'mistral-small-latest', 'https://proxy.example.com/');
        $this->assertSame('https://proxy.example.com', $config->baseUrl());
    }
}
