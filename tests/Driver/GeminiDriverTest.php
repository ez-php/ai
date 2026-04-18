<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\GeminiConfig;
use EzPhp\Ai\Driver\GeminiDriver;
use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Message\ContentPart;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\GeminiDriver
 * @covers \EzPhp\Ai\Driver\GeminiConfig
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
final class GeminiDriverTest extends TestCase
{
    private function makeDriver(FakeTransport $transport, ?GeminiConfig $config = null): GeminiDriver
    {
        return new GeminiDriver(
            new HttpClient($transport),
            $config ?? new GeminiConfig('test-key'),
        );
    }

    private function successBody(
        string $content = 'Hello!',
        string $finishReason = 'STOP',
        int $promptTokens = 10,
        int $candidatesTokens = 5,
    ): string {
        return (string) json_encode([
            'candidates' => [
                [
                    'content' => ['role' => 'model', 'parts' => [['text' => $content]]],
                    'finishReason' => $finishReason,
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => $promptTokens,
                'candidatesTokenCount' => $candidatesTokens,
            ],
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
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody('Hi there!'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('ping'));

        $this->assertSame('Hi there!', $response->content());
        $this->assertSame(FinishReason::STOP, $response->finishReason());
    }

    public function testTokenUsageIsMapped(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(promptTokens: 42, candidatesTokens: 17))]);
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

    public function testSendsPostToCorrectUrlWithDefaultModel(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $recorded = $transport->getRecorded();
        $this->assertSame('POST', $recorded[0]['method']);
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=test-key',
            $recorded[0]['url'],
        );
    }

    public function testRequestModelOverridesConfigInUrl(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withModel('gemini-2.5-pro'));

        $this->assertStringContainsString('gemini-2.5-pro', $transport->getRecorded()[0]['url']);
    }

    public function testFallsBackToConfigModelInUrl(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport, new GeminiConfig('key', 'gemini-1.5-flash'))->complete(AiRequest::make('hi'));

        $this->assertStringContainsString('gemini-1.5-flash', $transport->getRecorded()[0]['url']);
    }

    public function testApiKeyAppearsInUrl(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport, new GeminiConfig('my-secret-key'))->complete(AiRequest::make('hi'));

        $this->assertStringContainsString('key=my-secret-key', $transport->getRecorded()[0]['url']);
    }

    public function testSimpleMessageMappedToContents(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('Hello Gemini'));

        $body = $this->recordedBody($transport);
        $this->assertIsArray($body['contents']);
        /** @var list<array<string, mixed>> $contents */
        $contents = $body['contents'];
        $this->assertCount(1, $contents);
        $this->assertSame('user', $contents[0]['role']);
        $this->assertSame([['text' => 'Hello Gemini']], $contents[0]['parts']);
    }

    public function testAssistantMessageUsesModelRole(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $request = AiRequest::withMessages(
            AiMessage::user('Hi'),
            AiMessage::assistant('Hello!'),
            AiMessage::user('How are you?'),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $this->recordedBody($transport);
        $this->assertIsArray($body['contents']);
        /** @var list<array<string, mixed>> $contents */
        $contents = $body['contents'];
        $this->assertCount(3, $contents);
        $this->assertSame('user', $contents[0]['role']);
        $this->assertSame('model', $contents[1]['role']);
        $this->assertSame('user', $contents[2]['role']);
    }

    public function testTemperatureAndMaxTokensPlacedInGenerationConfig(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withTemperature(0.7)->withMaxTokens(256));

        $body = $this->recordedBody($transport);
        $this->assertIsArray($body['generationConfig']);
        /** @var array<string, mixed> $gc */
        $gc = $body['generationConfig'];
        $this->assertSame(0.7, $gc['temperature']);
        $this->assertSame(256, $gc['maxOutputTokens']);
    }

    public function testGenerationConfigAbsentWhenNoOptionals(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $body = $this->recordedBody($transport);
        $this->assertArrayNotHasKey('generationConfig', $body);
    }

    // ─── System prompt handling ───────────────────────────────────────────────

    public function testSystemPromptPlacedInSystemInstruction(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withSystemPrompt('Be concise.'));

        $body = $this->recordedBody($transport);
        $this->assertIsArray($body['systemInstruction']);
        /** @var array<string, mixed> $si */
        $si = $body['systemInstruction'];
        $this->assertSame([['text' => 'Be concise.']], $si['parts']);
    }

    public function testSystemRoleMessageFilteredIntoSystemInstruction(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $request = AiRequest::withMessages(
            AiMessage::system('You are a poet.'),
            AiMessage::user('Write a haiku.'),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $this->recordedBody($transport);
        $this->assertIsArray($body['systemInstruction']);
        /** @var array<string, mixed> $si */
        $si = $body['systemInstruction'];
        $this->assertSame([['text' => 'You are a poet.']], $si['parts']);
        $this->assertIsArray($body['contents']);
        /** @var list<array<string, mixed>> $contents */
        $contents = $body['contents'];
        $this->assertCount(1, $contents);
        $this->assertSame('user', $contents[0]['role']);
    }

    public function testSystemPromptAndSystemRoleMessageBothInSystemInstruction(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $request = AiRequest::withMessages(
            AiMessage::system('Part two.'),
            AiMessage::user('Hi'),
        )->withSystemPrompt('Part one.');
        $this->makeDriver($transport)->complete($request);

        $body = $this->recordedBody($transport);
        $this->assertIsArray($body['systemInstruction']);
        /** @var array<string, mixed> $si */
        $si = $body['systemInstruction'];
        $this->assertSame([['text' => 'Part one.'], ['text' => 'Part two.']], $si['parts']);
    }

    public function testSystemInstructionAbsentWhenNoSystemContent(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody())]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $body = $this->recordedBody($transport);
        $this->assertArrayNotHasKey('systemInstruction', $body);
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
        $this->assertIsArray($body['contents']);
        /** @var list<array<string, mixed>> $contents */
        $contents = $body['contents'];
        $this->assertIsArray($contents[0]['parts']);
        /** @var list<array<string, mixed>> $parts */
        $parts = $contents[0]['parts'];
        $this->assertCount(2, $parts);
        $this->assertSame('Describe:', $parts[0]['text']);
        $this->assertIsArray($parts[1]['fileData']);
        $this->assertSame('https://example.com/img.jpg', $parts[1]['fileData']['fileUri']);
    }

    public function testMultipleTextPartsAreConcatenatedInResponse(): void
    {
        $body = (string) json_encode([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [['text' => 'Hello '], ['text' => 'world']],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 2],
        ]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame('Hello world', $response->content());
    }

    // ─── Finish reason mapping ────────────────────────────────────────────────

    public function testMaxTokensMapsToLength(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(finishReason: 'MAX_TOKENS'))]);
        $this->assertSame(FinishReason::LENGTH, $this->makeDriver($transport)->complete(AiRequest::make('hi'))->finishReason());
    }

    public function testSafetyMapsToContentFilter(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(finishReason: 'SAFETY'))]);
        $this->assertSame(FinishReason::CONTENT_FILTER, $this->makeDriver($transport)->complete(AiRequest::make('hi'))->finishReason());
    }

    public function testRecitationMapsToContentFilter(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(finishReason: 'RECITATION'))]);
        $this->assertSame(FinishReason::CONTENT_FILTER, $this->makeDriver($transport)->complete(AiRequest::make('hi'))->finishReason());
    }

    public function testUnknownFinishReasonMapsToError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->successBody(finishReason: 'OTHER'))]);
        $this->assertSame(FinishReason::ERROR, $this->makeDriver($transport)->complete(AiRequest::make('hi'))->finishReason());
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testThrowsAiRequestExceptionOn4xx(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(400, '{"error":{"message":"API key not valid"}}')]);

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
        $errorBody = '{"error":{"message":"Quota exceeded","status":"RESOURCE_EXHAUSTED"}}';
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
        $transport = new FakeTransport(['*' => new HttpResponse(200, 'not json at all')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testThrowsWhenCandidatesMissing(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, '{"usageMetadata":{"promptTokenCount":1,"candidatesTokenCount":1}}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testThrowsWhenPartsMissing(): void
    {
        $body = (string) json_encode([
            'candidates' => [['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    public function testThrowsWhenNoTextInParts(): void
    {
        $body = (string) json_encode([
            'candidates' => [
                [
                    'content' => ['role' => 'model', 'parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => 'abc']]]],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }

    // ─── Config ───────────────────────────────────────────────────────────────

    public function testConfigDefaults(): void
    {
        $config = new GeminiConfig('key');
        $this->assertSame('gemini-2.0-flash', $config->model());
        $this->assertSame('key', $config->apiKey());
    }
}
