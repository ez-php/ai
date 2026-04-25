<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\OpenAiConfig;
use EzPhp\Ai\Driver\OpenAiDriver;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiStream;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\StreamingAiClientInterface;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\OpenAiDriver
 * @uses   \EzPhp\Ai\Driver\OpenAiConfig
 * @uses   \EzPhp\Ai\Request\AiRequest
 * @uses   \EzPhp\Ai\Response\AiStream
 * @uses   \EzPhp\Ai\Response\AiChunk
 * @uses   \EzPhp\Ai\Response\FinishReason
 * @uses   \EzPhp\Ai\Message\AiMessage
 * @uses   \EzPhp\Ai\Message\Role
 * @uses   \EzPhp\Ai\AiRequestException
 * @uses   \EzPhp\Ai\AiException
 */
final class OpenAiStreamTest extends TestCase
{
    private function makeDriver(FakeTransport $transport, ?OpenAiConfig $config = null): OpenAiDriver
    {
        return new OpenAiDriver(
            new HttpClient($transport),
            $config ?? new OpenAiConfig('test-key'),
        );
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

    private function chunk(string $content, ?string $finishReason = null): string
    {
        $choice = ['delta' => ['content' => $content], 'finish_reason' => $finishReason, 'index' => 0];

        return (string) json_encode(['choices' => [$choice]]);
    }

    // ─── Interface ───────────────────────────────────────────────────────────

    public function testImplementsStreamingInterface(): void
    {
        $this->assertInstanceOf(StreamingAiClientInterface::class, $this->makeDriver(new FakeTransport()));
    }

    // ─── Happy path ──────────────────────────────────────────────────────────

    public function testStreamReturnsAiStream(): void
    {
        $body = $this->sseBody($this->chunk('Hello'), '[DONE]');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $result = $this->makeDriver($transport)->stream(AiRequest::make('hi'));

        $this->assertInstanceOf(AiStream::class, $result);
    }

    public function testStreamYieldsChunksInOrder(): void
    {
        $body = $this->sseBody(
            $this->chunk('Hello'),
            $this->chunk(' world'),
            $this->chunk('', 'stop'),
            '[DONE]',
        );
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertCount(3, $chunks);
        $this->assertSame('Hello', $chunks[0]->content());
        $this->assertSame(' world', $chunks[1]->content());
        $this->assertSame(FinishReason::STOP, $chunks[2]->finishReason());
    }

    public function testCollectConcatenatesAllChunks(): void
    {
        $body = $this->sseBody($this->chunk('foo'), $this->chunk('bar'), '[DONE]');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $stream = $this->makeDriver($transport)->stream(AiRequest::make('hi'));

        $this->assertSame('foobar', $stream->collect());
    }

    public function testStreamSendsStreamTrue(): void
    {
        $body = $this->sseBody('[DONE]');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $this->makeDriver($transport)->stream(AiRequest::make('hi'));

        $recorded = $transport->getRecorded();
        $decoded = json_decode($recorded[0]['body'], true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['stream']);
    }

    public function testDoneLineIsSkipped(): void
    {
        $body = $this->sseBody('[DONE]');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertCount(0, $chunks);
    }

    public function testEmptyAndNonDataLinesAreSkipped(): void
    {
        $raw = "event: start\n\ndata: " . $this->chunk('ok') . "\n\ndata: [DONE]\n";
        $transport = new FakeTransport(['*' => new HttpResponse(200, $raw)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertCount(1, $chunks);
        $this->assertSame('ok', $chunks[0]->content());
    }

    public function testInvalidJsonLinesAreSkipped(): void
    {
        $raw = "data: not-json\ndata: " . $this->chunk('valid') . "\ndata: [DONE]\n";
        $transport = new FakeTransport(['*' => new HttpResponse(200, $raw)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertCount(1, $chunks);
    }

    public function testFinishReasonLengthMapped(): void
    {
        $body = $this->sseBody($this->chunk('', 'length'), '[DONE]');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertSame(FinishReason::LENGTH, $chunks[0]->finishReason());
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testStreamThrowsOnHttpError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(401, '{"error":"unauthorized"}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->stream(AiRequest::make('hi'));
    }
}
