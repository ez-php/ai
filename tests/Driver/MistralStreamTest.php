<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\MistralConfig;
use EzPhp\Ai\Driver\MistralDriver;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiStream;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\StreamingAiClientInterface;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\MistralDriver
 * @uses   \EzPhp\Ai\Driver\MistralConfig
 * @uses   \EzPhp\Ai\Driver\OpenAiDriver
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
final class MistralStreamTest extends TestCase
{
    private function makeDriver(FakeTransport $transport, ?MistralConfig $config = null): MistralDriver
    {
        return new MistralDriver(
            new HttpClient($transport),
            $config ?? new MistralConfig('test-key'),
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

        $this->assertInstanceOf(AiStream::class, $this->makeDriver($transport)->stream(AiRequest::make('hi')));
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

        $this->assertSame('foobar', $this->makeDriver($transport)->stream(AiRequest::make('hi'))->collect());
    }

    public function testStreamUsesDefaultMistralBaseUrl(): void
    {
        $body = $this->sseBody('[DONE]');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $this->makeDriver($transport)->stream(AiRequest::make('hi'));

        $url = $transport->getRecorded()[0]['url'];
        $this->assertStringContainsString('api.mistral.ai', $url);
    }

    public function testStreamUsesCustomBaseUrl(): void
    {
        $body = $this->sseBody('[DONE]');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $this->makeDriver($transport, new MistralConfig('key', 'mistral-small-latest', 'https://custom.example.com'))->stream(AiRequest::make('hi'));

        $url = $transport->getRecorded()[0]['url'];
        $this->assertStringContainsString('custom.example.com', $url);
    }

    public function testStreamSendsApiKeyAsBearerToken(): void
    {
        $body = $this->sseBody('[DONE]');
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);
        $this->makeDriver($transport, new MistralConfig('my-mistral-key'))->stream(AiRequest::make('hi'));

        $headers = $transport->getRecorded()[0]['headers'];
        $this->assertStringContainsString('my-mistral-key', $headers['Authorization'] ?? '');
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testStreamThrowsOnHttpError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(401, '{"error":"unauthorized"}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->stream(AiRequest::make('hi'));
    }
}
