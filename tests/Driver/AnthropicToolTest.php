<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\Driver\AnthropicConfig;
use EzPhp\Ai\Driver\AnthropicDriver;
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
 * @covers \EzPhp\Ai\Driver\AnthropicDriver
 * @uses   \EzPhp\Ai\Driver\AnthropicConfig
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
final class AnthropicToolTest extends TestCase
{
    private function makeDriver(FakeTransport $transport): AnthropicDriver
    {
        return new AnthropicDriver(new HttpClient($transport), new AnthropicConfig('test-key'));
    }

    private function toolUseResponse(string $id, string $name, mixed $input): string
    {
        return (string) json_encode([
            'content' => [
                ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
        ]);
    }

    private function textResponse(string $content): string
    {
        return (string) json_encode([
            'content' => [['type' => 'text', 'text' => $content]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
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
        $this->assertStringContainsString('"get_weather"', $body);
        $this->assertStringContainsString('"Get weather"', $body);
        $this->assertStringContainsString('"input_schema"', $body);
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

    public function testResponseWithToolUseHasToolCallFinishReason(): void
    {
        $body = $this->toolUseResponse('toolu_1', 'get_weather', ['city' => 'Paris']);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertSame(FinishReason::TOOL_CALL, $response->finishReason());
    }

    public function testResponseWithToolUsePopulatesToolCalls(): void
    {
        $body = $this->toolUseResponse('toolu_abc', 'search', ['query' => 'PHP 8.5']);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertTrue($response->hasToolCalls());
        $this->assertCount(1, $response->toolCalls());
        $this->assertSame('toolu_abc', $response->toolCalls()[0]->id());
        $this->assertSame('search', $response->toolCalls()[0]->name());
        $this->assertSame(['query' => 'PHP 8.5'], $response->toolCalls()[0]->arguments());
    }

    public function testResponseWithoutToolUseHasEmptyToolCalls(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('Hello'))]);
        $response = $this->makeDriver($transport)->complete(AiRequest::make('hi'));

        $this->assertFalse($response->hasToolCalls());
        $this->assertSame([], $response->toolCalls());
    }

    // ─── Tool result messages ─────────────────────────────────────────────────

    public function testToolResultMessageSerializedAsUserWithToolResultBlock(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('Done'))]);
        $request = AiRequest::withMessages(
            AiMessage::user('What is the weather?'),
            AiMessage::assistantWithToolCalls(new ToolCall('toolu_1', 'get_weather', ['city' => 'Paris'])),
            AiMessage::tool('Sunny, 20°C', 'toolu_1'),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $transport->getRecorded()[0]['body'];
        $this->assertStringContainsString('"tool_result"', $body);
        $this->assertStringContainsString('"tool_use_id"', $body);
        $this->assertStringContainsString('"toolu_1"', $body);
        $this->assertStringContainsString('Sunny', $body);
    }

    public function testAssistantWithToolCallsSerializedAsToolUseBlocks(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->textResponse('Done'))]);
        $toolCall = new ToolCall('toolu_xyz', 'calculate', ['x' => 2]);
        $request = AiRequest::withMessages(
            AiMessage::user('Calculate'),
            AiMessage::assistantWithToolCalls($toolCall),
            AiMessage::tool('4', 'toolu_xyz'),
        );
        $this->makeDriver($transport)->complete($request);

        $body = $transport->getRecorded()[0]['body'];
        $this->assertStringContainsString('"tool_use"', $body);
        $this->assertStringContainsString('"toolu_xyz"', $body);
        $this->assertStringContainsString('"calculate"', $body);
    }
}
