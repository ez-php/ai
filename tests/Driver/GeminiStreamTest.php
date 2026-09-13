<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\AiStreamException;
use EzPhp\Ai\Driver\GeminiConfig;
use EzPhp\Ai\Driver\GeminiDriver;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiStream;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\StreamingAiClientInterface;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\HttpStream;
use EzPhp\HttpClient\HttpStreamException;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\GeminiDriver
 * @uses   \EzPhp\Ai\Driver\ProviderStream
 * @uses   \EzPhp\Ai\AiStreamException
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
        $body = '';

        foreach ($dataLines as $data) {
            $body .= 'data: ' . $data . "\n\n";
        }

        return $body;
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

    public function testStreamUrlContainsModelAndApiKeySentAsHeader(): void
    {
        $body = $this->sseBody($this->candidate('ok', 'STOP'));
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $this->makeDriver($transport, new GeminiConfig('my-key', 'gemini-2.5-pro'))->stream(AiRequest::make('hi'));

        $recorded = $transport->getRecorded()[0];
        $this->assertStringContainsString('gemini-2.5-pro', $recorded['url']);
        $this->assertStringNotContainsString('my-key', $recorded['url']);
        $this->assertSame('my-key', $recorded['headers']['x-goog-api-key']);
    }

    public function testFinishOnlyCandidateYieldsAFinalChunk(): void
    {
        $finishOnly = (string) json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'STOP']]]);
        $body = $this->sseBody($this->candidate('real'), $finishOnly);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertCount(2, $chunks);
        $this->assertSame('real', $chunks[0]->content());
        $this->assertNull($chunks[0]->finishReason());
        $this->assertSame('', $chunks[1]->content());
        $this->assertSame(FinishReason::STOP, $chunks[1]->finishReason());
    }

    public function testCandidateWithoutTextOrFinishReasonIsSkipped(): void
    {
        $empty = (string) json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => []]]]]);
        $body = $this->sseBody($empty, $this->candidate('x', 'STOP'));
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $chunks = iterator_to_array($this->makeDriver($transport)->stream(AiRequest::make('hi')));

        $this->assertCount(1, $chunks);
        $this->assertSame('x', $chunks[0]->content());
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

    // ─── Incremental transport ────────────────────────────────────────────────

    public function testEventsSplitAcrossChunksYieldTheSameChunks(): void
    {
        $body = $this->sseBody($this->candidate('Hello'), $this->candidate(' world', 'STOP'));
        $transport = new FakeTransport(['*' => HttpStream::fake(str_split($body, 6))]);

        $this->assertSame('Hello world', $this->makeDriver($transport)->stream(AiRequest::make('hi'))->collect());
    }

    public function testErrorEventThrows(): void
    {
        $error = (string) json_encode(['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']]);
        $body = $this->sseBody($this->candidate('partial'), $error);
        $stream = $this->makeDriver(new FakeTransport(['*' => HttpStream::fake([$body])]))->stream(AiRequest::make('hi'));

        try {
            $stream->collect();
            $this->fail('Expected AiStreamException.');
        } catch (AiStreamException $e) {
            $this->assertSame('Quota exceeded', $e->getMessage());
            $this->assertSame('RESOURCE_EXHAUSTED', $e->providerErrorType());
        }
    }

    public function testStreamWithoutFinishReasonThrowsTruncated(): void
    {
        $stream = $this->makeDriver(new FakeTransport(['*' => HttpStream::fake([$this->sseBody($this->candidate('cut'))])]))
            ->stream(AiRequest::make('hi'));

        $this->expectException(AiStreamException::class);
        $this->expectExceptionMessage(AiStreamException::TRUNCATED_MESSAGE);

        $stream->collect();
    }

    public function testTransportFailureIsWrappedInAiStreamException(): void
    {
        $cause = new HttpStreamException('Connection lost: reset');
        $fixture = HttpStream::fake([$this->sseBody($this->candidate('a')), $cause]);
        $stream = $this->makeDriver(new FakeTransport(['*' => $fixture]))->stream(AiRequest::make('hi'));

        try {
            $stream->collect();
            $this->fail('Expected AiStreamException.');
        } catch (AiStreamException $e) {
            $this->assertSame($cause, $e->getPrevious());
        }
    }

    public function testIdleTimeoutIsSentToTheTransport(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->sseBody($this->candidate('x', 'STOP')))]);
        $this->makeDriver($transport)->stream(AiRequest::make('hi'));

        $transport2 = new FakeTransport(['*' => new HttpResponse(200, $this->sseBody($this->candidate('x', 'STOP')))]);
        (new GeminiDriver(new HttpClient($transport2), new GeminiConfig('test-key'), 11))->stream(AiRequest::make('hi'));

        $this->assertSame(120, $transport->getRecorded()[0]['idleTimeoutSeconds']);
        $this->assertSame(11, $transport2->getRecorded()[0]['idleTimeoutSeconds']);
    }
}
