<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\OpenAiConfig;
use EzPhp\Ai\Driver\OpenAiEmbeddingDriver;
use EzPhp\Ai\EmbeddingClientInterface;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\OpenAiEmbeddingDriver
 * @uses   \EzPhp\Ai\Driver\OpenAiConfig
 * @uses   \EzPhp\Ai\AiRequestException
 * @uses   \EzPhp\Ai\AiException
 */
final class OpenAiEmbeddingDriverTest extends TestCase
{
    private function makeDriver(FakeTransport $transport, ?OpenAiConfig $config = null): OpenAiEmbeddingDriver
    {
        return new OpenAiEmbeddingDriver(
            new HttpClient($transport),
            $config ?? new OpenAiConfig('test-key'),
        );
    }

    private function embeddingResponse(float ...$values): string
    {
        return (string) json_encode([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => array_values($values)],
            ],
            'model' => 'text-embedding-3-small',
        ]);
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

        $decoded = json_decode($transport->getRecorded()[0]['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('my text', $decoded['input']);
    }

    public function testEmbedUsesDefaultModel(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1))]);
        $this->makeDriver($transport)->embed('hi');

        $decoded = json_decode($transport->getRecorded()[0]['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(OpenAiEmbeddingDriver::DEFAULT_EMBEDDING_MODEL, $decoded['model']);
    }

    public function testEmbedUsesOverrideModel(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1))]);
        $this->makeDriver($transport)->embed('hi', 'text-embedding-3-large');

        $decoded = json_decode($transport->getRecorded()[0]['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('text-embedding-3-large', $decoded['model']);
    }

    public function testEmbedUrlContainsEmbeddingsEndpoint(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1))]);
        $this->makeDriver($transport)->embed('hi');

        $this->assertStringContainsString('/v1/embeddings', $transport->getRecorded()[0]['url']);
    }

    public function testEmbedSendsApiKeyAsBearerToken(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1))]);
        $this->makeDriver($transport, new OpenAiConfig('secret-key'))->embed('hi');

        $headers = $transport->getRecorded()[0]['headers'];
        $this->assertStringContainsString('secret-key', $headers['Authorization'] ?? '');
    }

    public function testEmbedUsesCustomBaseUrl(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, $this->embeddingResponse(0.1))]);
        $this->makeDriver($transport, new OpenAiConfig('key', 'gpt-4o-mini', 'https://my-proxy.example.com'))->embed('hi');

        $this->assertStringContainsString('my-proxy.example.com', $transport->getRecorded()[0]['url']);
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function testEmbedThrowsOnHttpError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(401, '{"error":"unauthorized"}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->embed('hi');
    }

    public function testEmbedThrowsOnMissingData(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, '{"object":"list"}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->embed('hi');
    }

    public function testEmbedThrowsOnMissingEmbedding(): void
    {
        $body = (string) json_encode(['data' => [['index' => 0]]]);
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

    /**
     * @param list<float> ...$vectors
     */
    private function batchResponse(array ...$vectors): string
    {
        $data = [];

        foreach ($vectors as $i => $vector) {
            $data[] = ['object' => 'embedding', 'index' => $i, 'embedding' => $vector];
        }

        return (string) json_encode(['object' => 'list', 'data' => $data]);
    }

    public function testEmbedBatchReturnsList(): void
    {
        $body = $this->batchResponse([0.1, 0.2], [0.3, 0.4]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $result = $this->makeDriver($transport)->embedBatch(['first', 'second']);

        $this->assertCount(2, $result);
        $this->assertSame(0.1, $result[0][0]);
        $this->assertSame(0.3, $result[1][0]);
    }

    public function testEmbedBatchSendsInputArrayInBody(): void
    {
        $body = $this->batchResponse([0.1], [0.2]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->makeDriver($transport)->embedBatch(['alpha', 'beta']);

        $decoded = json_decode($transport->getRecorded()[0]['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(['alpha', 'beta'], $decoded['input']);
    }

    public function testEmbedBatchUsesDefaultModel(): void
    {
        $body = $this->batchResponse([0.1]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->makeDriver($transport)->embedBatch(['hi']);

        $decoded = json_decode($transport->getRecorded()[0]['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(OpenAiEmbeddingDriver::DEFAULT_EMBEDDING_MODEL, $decoded['model']);
    }

    public function testEmbedBatchUsesOverrideModel(): void
    {
        $body = $this->batchResponse([0.1]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->makeDriver($transport)->embedBatch(['hi'], 'text-embedding-3-large');

        $decoded = json_decode($transport->getRecorded()[0]['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('text-embedding-3-large', $decoded['model']);
    }

    public function testEmbedBatchThrowsOnHttpError(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(500, '{"error":"server error"}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->embedBatch(['hi']);
    }

    public function testEmbedBatchThrowsOnMissingData(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, '{}')]);

        $this->expectException(AiRequestException::class);
        $this->makeDriver($transport)->embedBatch(['hi']);
    }

    public function testEmbedBatchSendsOnlyOneRequest(): void
    {
        $body = $this->batchResponse([0.1], [0.2], [0.3]);
        $transport = new FakeTransport(['*' => new HttpResponse(200, $body)]);

        $this->makeDriver($transport)->embedBatch(['a', 'b', 'c']);

        $this->assertCount(1, $transport->getRecorded());
    }
}
