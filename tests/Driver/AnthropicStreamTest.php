<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\AnthropicConfig;
use EzPhp\Ai\Driver\AnthropicDriver;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiStream;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\StreamingAiClientInterface;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\AnthropicDriver
 * @uses   \EzPhp\Ai\Driver\AnthropicConfig
 * @uses   \EzPhp\Ai\Request\AiRequest
 * @uses   \EzPhp\Ai\Response\AiStream
 * @uses   \EzPhp\Ai\Response\AiChunk
 * @uses   \EzPhp\Ai\Response\FinishReason
 * @uses   \EzPhp\Ai\Message\AiMessage
 * @uses   \EzPhp\Ai\Message\Role
 * @uses   \EzPhp\Ai\AiRequestException
 * @uses   \EzPhp\Ai\AiException
 */
final class AnthropicStreamTest extends TestCase
{
    private function makeDriver(FakeTransport $transport): AnthropicDriver
    {
        return new AnthropicDriver(new HttpClient($transport), new AnthropicConfig('test-key'));
    }

    private function sseBody(string ...$dataLines): string
    {
        $lines = [];

        foreach ($dataLines as $data) {
            $lines[] = 'data: ' . $data;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function delta(string $text): string
    {
        return (string) json_encode([
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]);
    }

    private function messageDelta(string $stopReason = 'end_turn'): string
    {
        return (string) json_encode([
            'type' => 'message_delta',
            'delta' => ['stop_reason' => $stopReason, 'stop_sequence' => null],
        ]);
    }

    // ─── Interface ───────────────────────────────────────────────────────────

    public function testImplementsStreamingInterface(): void
    {
        $this->assertInstanceOf(StreamingAiClientInterface::class, $this->makeDriver(new FakeTransport()));
    }

    // ─── Happy path ──────────────────────────────────────────────────────────

    public function testStreamReturnsAiStream(): void
    {
        $body = $this->sseBody($this->delta('Hello'), $this->messageDelta());
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->assertInstanceOf(AiStream::class, $this->makeDriver($transport)->stream(AiRequest::make('hi')));
    }

    public function testStreamYieldsTextDeltasAndFinalChunk(): void
    {
        $body = $this->sseBody(
            $this->delta('Hello'),
            $this->delta(' world'),
            $this->messageDelta(),
        );
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertCount(3, $chunks);
        $this->assertSame('Hello', $chunks[0]->content());
        $this->assertSame(' world', $chunks[1]->content());
        $this->assertNull($chunks[0]->finishReason());
        $this->assertSame(FinishReason::STOP, $chunks[2]->finishReason());
    }

    public function testCollectConcatenatesContent(): void
    {
        $body = $this->sseBody($this->delta('foo'), $this->delta('bar'), $this->messageDelta());
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $stream = $this->makeDriver($transport)->stream(AiRequest::make('hi'));

        $this->assertSame('foobar', $stream->collect());
    }

    public function testStreamSendsStreamTrue(): void
    {
        $body = $this->sseBody($this->messageDelta());
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $this->makeDriver($transport)->stream(AiRequest::make('hi'));

        $decoded = json_decode($transport->getRecorded()[0]['body'], true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['stream']);
    }

    public function testNonDeltaLinesAreSkipped(): void
    {
        $body = $this->sseBody(
            (string) json_encode(['type' => 'ping']),
            (string) json_encode(['type' => 'message_start', 'message' => ['id' => 'x']]),
            $this->delta('ok'),
            $this->messageDelta(),
        );
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertCount(2, $chunks);
        $this->assertSame('ok', $chunks[0]->content());
    }

    public function testMaxTokensStopReasonMapped(): void
    {
        $body = $this->sseBody($this->delta('x'), $this->messageDelta('max_tokens'));
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $finalChunk = end($chunks);
        $this->assertNotFalse($finalChunk);
        $this->assertSame(FinishReason::LENGTH, $finalChunk->finishReason());
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testStreamThrowsOnHttpError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(401, '{"type":"error"}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->stream(AiRequest::make('hi'));
    }
}
