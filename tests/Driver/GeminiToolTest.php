<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\Driver\GeminiConfig;
use EzPhp\Ai\Driver\GeminiDriver;
use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Tool\ToolCall;
use EzPhp\Ai\Tool\ToolDefinition;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\GeminiDriver
 * @uses   \EzPhp\Ai\Driver\GeminiConfig
 * @uses   \EzPhp\Ai\Request\AiRequest
 * @uses   \EzPhp\Ai\Response\AiResponse
 * @uses   \EzPhp\Ai\Response\FinishReason
 * @uses   \EzPhp\Ai\Response\TokenUsage
 * @uses   \EzPhp\Ai\Message\AiMessage
 * @uses   \EzPhp\Ai\Message\Role
 * @uses   \EzPhp\Ai\Tool\ToolDefinition
 * @uses   \EzPhp\Ai\Tool\ToolCall
 * @uses   \EzPhp\Ai\AiRequestException
 * @uses   \EzPhp\Ai\AiException
 */
final class GeminiToolTest extends TestCase
{
    private function makeDriver(FakeTransport $transport): GeminiDriver
    {
        return new GeminiDriver(new HttpClient($transport), new GeminiConfig('test-key'));
    }

    private function functionCallResponse(string $name, mixed $args): string
    {
        return (string) json_encode([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [['functionCall' => ['name' => $name, 'args' => $args]]],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]);
    }

    private function textResponse(string $content): string
    {
        return (string) json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => $content]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 3],
        ]);
    }

    // ─── Tool definitions ─────────────────────────────────────────────────────

    public function testToolsSerializedAsFunctionDeclarations(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('ok'))]);
        $tool = new ToolDefinition('get_weather', 'Get weather', ['type' => 'object', 'properties' => []]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withTools($tool));

        $body = $transport->getRecorded()[0]['body'];
        $this->assertStringContainsString('"tools"', $body);
        $this->assertStringContainsString('"function_declarations"', $body);
        $this->assertStringContainsString('"get_weather"', $body);
        $this->assertStringContainsString('"Get weather"', $body);
    }

    public function testNoToolsKeyWhenNoToolsDefined(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('ok'))]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $decoded = json_decode($transport->getRecorded()[0]['body'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('tools', $decoded);
    }

    // ─── Tool call response parsing ──────────────────────────────────────────

    public function testResponseWithFunctionCallHasToolCallFinishReason(): void
    {
        $body = $this->functionCallResponse('get_weather', ['city' => 'Paris']);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::TOOL_CALL, $response->finishReason());
    }

    public function testResponseWithFunctionCallPopulatesToolCalls(): void
    {
        $body = $this->functionCallResponse('search', ['query' => 'PHP']);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertTrue($response->hasToolCalls());
        $this->assertCount(1, $response->toolCalls());
        $this->assertSame('search', $response->toolCalls()[0]->id());
        $this->assertSame('search', $response->toolCalls()[0]->name());
        $this->assertSame(['query' => 'PHP'], $response->toolCalls()[0]->arguments());
    }

    public function testResponseWithoutFunctionCallHasEmptyToolCalls(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('Hello'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertFalse($response->hasToolCalls());
        $this->assertSame([], $response->toolCalls());
    }

    // ─── Tool result messages ─────────────────────────────────────────────────

    public function testToolResultMessageSerializedAsFunctionResponse(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('Done'))]);
        $request = AiRequest::withMessages(
            AiMessage::user('What is the weather?'),
            AiMessage::assistantWithToolCalls(new ToolCall('get_weather', 'get_weather', ['city' => 'Paris'])),
            AiMessage::tool('Sunny, 20°C', 'get_weather'),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $transport->getRecorded()[0]['body'];
        $this->assertStringContainsString('"functionResponse"', $body);
        $this->assertStringContainsString('"get_weather"', $body);
        $this->assertStringContainsString('Sunny', $body);
    }

    public function testAssistantWithToolCallsSerializedAsFunctionCallParts(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('Done'))]);
        $toolCall = new ToolCall('get_weather', 'get_weather', ['city' => 'Berlin']);
        $request = AiRequest::withMessages(
            AiMessage::user('Weather?'),
            AiMessage::assistantWithToolCalls($toolCall),
            AiMessage::tool('Cloudy', 'get_weather'),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $transport->getRecorded()[0]['body'];
        $this->assertStringContainsString('"functionCall"', $body);
        $this->assertStringContainsString('"get_weather"', $body);
        $this->assertStringContainsString('"Berlin"', $body);
    }
}
