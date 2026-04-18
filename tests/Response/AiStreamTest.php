<?php

declare(strict_types=1);

namespace Tests\Ai\Response;

use EzPhp\Ai\Response\AiChunk;
use EzPhp\Ai\Response\AiStream;
use EzPhp\Ai\Response\FinishReason;
use Generator;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Response\AiStream
 * @uses   \EzPhp\Ai\Response\AiChunk
 * @uses   \EzPhp\Ai\Response\FinishReason
 */
final class AiStreamTest extends TestCase
{
    /**
     * @param list<AiChunk> $chunks
     *
     * @return Generator<int, AiChunk, void, void>
     */
    private function makeGenerator(array $chunks): Generator
    {
        yield from $chunks;
    }

    public function testIteratesChunksInOrder(): void
    {
        $chunks = [
            new AiChunk('Hello'),
            new AiChunk(' world'),
            new AiChunk('', FinishReason::STOP),
        ];

        $stream = new AiStream($this->makeGenerator($chunks));
        $collected = [];

        foreach ($stream as $chunk) {
            $collected[] = $chunk->content();
        }

        $this->assertSame(['Hello', ' world', ''], $collected);
    }

    public function testCollectConcatenatesContent(): void
    {
        $chunks = [
            new AiChunk('foo'),
            new AiChunk('bar'),
            new AiChunk('baz'),
        ];

        $stream = new AiStream($this->makeGenerator($chunks));

        $this->assertSame('foobarbaz', $stream->collect());
    }

    public function testCollectOnEmptyStreamReturnsEmptyString(): void
    {
        $stream = new AiStream($this->makeGenerator([]));

        $this->assertSame('', $stream->collect());
    }

    public function testGetIteratorReturnsGenerator(): void
    {
        $stream = new AiStream($this->makeGenerator([new AiChunk('x')]));

        $this->assertInstanceOf(Generator::class, $stream->getIterator());
    }

    public function testStreamIsOneShot(): void
    {
        $chunks = [new AiChunk('a'), new AiChunk('b')];
        $stream = new AiStream($this->makeGenerator($chunks));

        $first = $stream->collect();
        $second = $stream->collect();

        $this->assertSame('ab', $first);
        $this->assertSame('', $second);
    }

    public function testSingleChunkStream(): void
    {
        $stream = new AiStream($this->makeGenerator([new AiChunk('only')]));

        $this->assertSame('only', $stream->collect());
    }
}
