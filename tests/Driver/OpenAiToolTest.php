<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\OpenAiConfig;
use EzPhp\Ai\Driver\OpenAiDriver;
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
 * @covers \EzPhp\Ai\Driver\OpenAiDriver
 * @uses   \EzPhp\Ai\Driver\OpenAiConfig
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
final class OpenAiToolTest extends TestCase
{
    private function makeDriver(FakeTransport $transport): OpenAiDriver
    {
        return new OpenAiDriver(new HttpClient($transport), new OpenAiConfig('test-key'));
    }

    private function toolCallResponse(string $id, string $name, string $arguments): string
    {
        return (string) json_encode([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $id,
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => $arguments],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ]);
    }

    private function textResponse(string $content): string
    {
        return (string) json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
        ]);
    }

    // ─── Tool definitions ─────────────────────────────────────────────────────

    public function testToolsSerializedInRequestBody(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('ok'))]);
        $tool = new ToolDefinition('get_weather', 'Get weather', ['type' => 'object', 'properties' => []]);
        $this->makeDriver($transport)->complete(AiRequest::make('hi')->withTools($tool));

        $body = $transport->getRecorded()[0]['body'];
        $this->assertStringContainsString('"tools"', $body);
        $this->assertStringContainsString('"function"', $body);
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

    public function testResponseWithToolCallsHasToolCallFinishReason(): void
    {
        $body = $this->toolCallResponse('call_1', 'get_weather', '{"city":"Paris"}');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::TOOL_CALL, $response->finishReason());
    }

    public function testResponseWithToolCallsPopulatesToolCalls(): void
    {
        $body = $this->toolCallResponse('call_abc', 'get_weather', '{"city":"Berlin"}');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertTrue($response->hasToolCalls());
        $this->assertCount(1, $response->toolCalls());
        $this->assertSame('call_abc', $response->toolCalls()[0]->id());
        $this->assertSame('get_weather', $response->toolCalls()[0]->name());
        $this->assertSame(['city' => 'Berlin'], $response->toolCalls()[0]->arguments());
    }

    public function testResponseWithoutToolCallsHasEmptyToolCalls(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('Hello'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertFalse($response->hasToolCalls());
        $this->assertSame([], $response->toolCalls());
    }

    // ─── Tool result messages ─────────────────────────────────────────────────

    public function testToolResultMessageSerializedCorrectly(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('Done'))]);
        $request = AiRequest::withMessages(
            AiMessage::user('What is the weather?'),
            AiMessage::assistantWithToolCalls(new ToolCall('call_1', 'get_weather', ['city' => 'Paris'])),
            AiMessage::tool('Sunny, 20°C', 'call_1'),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $transport->getRecorded()[0]['body'];
        $this->assertStringContainsString('"tool"', $body);
        $this->assertStringContainsString('"tool_call_id"', $body);
        $this->assertStringContainsString('"call_1"', $body);
        $this->assertStringContainsString('Sunny, 20\u00b0C', $body);
    }

    public function testAssistantWithToolCallsSerializedCorrectly(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('Done'))]);
        $toolCall = new ToolCall('call_xyz', 'calculate', ['x' => 2, 'y' => 3]);
        $request = AiRequest::withMessages(
            AiMessage::user('Calculate 2+3'),
            AiMessage::assistantWithToolCalls($toolCall),
            AiMessage::tool('5', 'call_xyz'),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $transport->getRecorded()[0]['body'];
        $this->assertStringContainsString('"tool_calls"', $body);
        $this->assertStringContainsString('"call_xyz"', $body);
        $this->assertStringContainsString('"calculate"', $body);
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testThrowsOnInvalidToolCallArguments(): void
    {
        $body = $this->toolCallResponse('call_1', 'fn', 'not-valid-json');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->complete(AiRequest::make('hi'));
    }
}
