<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\GeminiConfig;
use EzPhp\Ai\Driver\GeminiDriver;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiStream;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\StreamingAiClientInterface;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\GeminiDriver
 * @uses   \EzPhp\Ai\Driver\GeminiConfig
 * @uses   \EzPhp\Ai\Request\AiRequest
 * @uses   \EzPhp\Ai\Response\AiStream
 * @uses   \EzPhp\Ai\Response\AiChunk
 * @uses   \EzPhp\Ai\Response\FinishReason
 * @uses   \EzPhp\Ai\Message\AiMessage
 * @uses   \EzPhp\Ai\Message\Role
 * @uses   \EzPhp\Ai\AiRequestException
 * @uses   \EzPhp\Ai\AiException
 */
final class GeminiStreamTest extends TestCase
{
    private function makeDriver(FakeTransport $transport, ?GeminiConfig $config = null): GeminiDriver
    {
        return new GeminiDriver(
            new HttpClient($transport),
            $config ?? new GeminiConfig('test-key'),
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

    private function candidate(string $text, ?string $finishReason = null): string
    {
        $candidate = [
            'content' => ['role' => 'model', 'parts' => [['text' => $text]]],
        ];

        if ($finishReason !== null) {
            $candidate['finishReason'] = $finishReason;
        }

        return (string) json_encode(['candidates' => [$candidate]]);
    }

    // ─── Interface ───────────────────────────────────────────────────────────

    public function testImplementsStreamingInterface(): void
    {
        $this->assertInstanceOf(StreamingAiClientInterface::class, $this->makeDriver(new FakeTransport()));
    }

    // ─── Happy path ──────────────────────────────────────────────────────────

    public function testStreamReturnsAiStream(): void
    {
        $body = $this->sseBody($this->candidate('Hello', 'STOP'));
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->assertInstanceOf(AiStream::class, $this->makeDriver($transport)->stream(AiRequest::make('hi')));
    }

    public function testStreamYieldsChunks(): void
    {
        $body = $this->sseBody(
            $this->candidate('Hello'),
            $this->candidate(' world', 'STOP'),
        );
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertCount(2, $chunks);
        $this->assertSame('Hello', $chunks[0]->content());
        $this->assertNull($chunks[0]->finishReason());
        $this->assertSame(' world', $chunks[1]->content());
        $this->assertSame(FinishReason::STOP, $chunks[1]->finishReason());
    }

    public function testCollectConcatenatesContent(): void
    {
        $body = $this->sseBody($this->candidate('foo'), $this->candidate('bar', 'STOP'));
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->assertSame('foobar', $this->makeDriver($transport)->stream(AiRequest::make('hi'))->collect());
    }

    public function testStreamUrlContainsStreamGenerateContentAndAltSse(): void
    {
        $body = $this->sseBody($this->candidate('hi', 'STOP'));
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $this->makeDriver($transport)->stream(AiRequest::make('hi'));

        $url = $transport->getRecorded()[0]['url'];
        $this->assertStringContainsString('streamGenerateContent', $url);
        $this->assertStringContainsString('alt=sse', $url);
    }

    public function testStreamUrlContainsModelAndApiKey(): void
    {
        $body = $this->sseBody($this->candidate('ok', 'STOP'));
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $this->makeDriver($transport, new GeminiConfig('my-key', 'gemini-2.5-pro'))->stream(AiRequest::make('hi'));

        $url = $transport->getRecorded()[0]['url'];
        $this->assertStringContainsString('gemini-2.5-pro', $url);
        $this->assertStringContainsString('key=my-key', $url);
    }

    public function testCandidatesWithoutTextAreSkipped(): void
    {
        $noText = (string) json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'STOP']]]);
        $body = $this->sseBody($this->candidate('real', 'STOP'), $noText);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertCount(1, $chunks);
        $this->assertSame('real', $chunks[0]->content());
    }

    public function testSafetyFinishReasonMappedToContentFilter(): void
    {
        $body = $this->sseBody($this->candidate('x', 'SAFETY'));
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertSame(FinishReason::CONTENT_FILTER, $chunks[0]->finishReason());
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testStreamThrowsOnHttpError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(400, '{"error":"bad key"}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->stream(AiRequest::make('hi'));
    }
}
