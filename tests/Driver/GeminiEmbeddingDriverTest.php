<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\GeminiConfig;
use EzPhp\Ai\Driver\GeminiEmbeddingDriver;
use EzPhp\Ai\EmbeddingClientInterface;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\GeminiEmbeddingDriver
 * @uses   \EzPhp\Ai\Driver\GeminiConfig
 * @uses   \EzPhp\Ai\AiRequestException
 * @uses   \EzPhp\Ai\AiException
 */
final class GeminiEmbeddingDriverTest extends TestCase
{
    private function makeDriver(FakeTransport $transport, ?GeminiConfig $config = null): GeminiEmbeddingDriver
    {
        return new GeminiEmbeddingDriver(
            new HttpClient($transport),
            $config ?? new GeminiConfig('test-key'),
        );
    }

    private function embeddingResponse(float ...$values): string
    {
        return (string) json_encode(['embedding' => ['values' => array_values($values)]]);
    }

    // ─── Interface ───────────────────────────────────────────────────────────

    public function testImplementsEmbeddingClientInterface(): void
    {
        $this->assertInstanceOf(EmbeddingClientInterface::class, $this->makeDriver(new FakeTransport()));
    }

    // ─── Happy path ──────────────────────────────────────────────────────────

    public function testEmbedReturnsFloatArray(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1, 0.2, 0.3))]);
        $result = $this->makeDriver($transport)->embed('hello');

        $this->assertCount(3, $result);
    }

    public function testEmbedReturnsCorrectValues(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.5, -0.25, 1.0))]);
        $result = $this->makeDriver($transport)->embed('test input');

        $this->assertSame(0.5, $result[0]);
        $this->assertSame(-0.25, $result[1]);
        $this->assertSame(1.0, $result[2]);
    }

    public function testEmbedSendsInputInBody(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1))]);
        $this->makeDriver($transport)->embed('my text');

        $this->assertStringContainsString('"my text"', $transport->getRecorded()[0]['body']);
    }

    public function testEmbedUrlContainsEmbedContent(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1))]);
        $this->makeDriver($transport)->embed('hi');

        $this->assertStringContainsString('embedContent', $transport->getRecorded()[0]['url']);
    }

    public function testEmbedUrlContainsDefaultModel(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1))]);
        $this->makeDriver($transport)->embed('hi');

        $this->assertStringContainsString(GeminiEmbeddingDriver::DEFAULT_EMBEDDING_MODEL, $transport->getRecorded()[0]['url']);
    }

    public function testEmbedUrlContainsOverrideModel(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1))]);
        $this->makeDriver($transport)->embed('hi', 'text-multilingual-embedding-002');

        $this->assertStringContainsString('text-multilingual-embedding-002', $transport->getRecorded()[0]['url']);
    }

    public function testEmbedUrlContainsApiKey(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1))]);
        $this->makeDriver($transport, new GeminiConfig('my-api-key'))->embed('hi');

        $this->assertStringContainsString('key=my-api-key', $transport->getRecorded()[0]['url']);
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testEmbedThrowsOnHttpError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(400, '{"error":"bad request"}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->embed('hi');
    }

    public function testEmbedThrowsOnMissingEmbeddingField(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, '{}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->embed('hi');
    }

    public function testEmbedThrowsOnMissingValues(): void
    {
        $body = (string) json_encode(['embedding' => ['model' => 'text-embedding-004']]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->embed('hi');
    }

    public function testEmbedThrowsOnNonJsonBody(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, 'not-json')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->embed('hi');
    }

    // ─── embedBatch ──────────────────────────────────────────────────────────

    public function testEmbedBatchReturnsList(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1, 0.2))]);

        $result = $this->makeDriver($transport)->embedBatch(['first', 'second']);

        $this->assertCount(2, $result);
        $this->assertSame(0.1, $result[0][0]);
        $this->assertSame(0.1, $result[1][0]);
    }

    public function testEmbedBatchIssuesOneRequestPerInput(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.5))]);

        $this->makeDriver($transport)->embedBatch(['a', 'b', 'c']);

        $this->assertCount(3, $transport->getRecorded());
    }

    public function testEmbedBatchReturnsEmptyForEmptyInput(): void
    {
        $transport = new FakeTransport();

        $result = $this->makeDriver($transport)->embedBatch([]);

        $this->assertSame([], $result);
    }

    public function testEmbedBatchThrowsOnHttpError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(500, '{"error":"server error"}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->embedBatch(['hi']);
    }
}
