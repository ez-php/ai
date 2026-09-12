<?php

declare(strict_types=1);

namespace Tests\Ai\Response;

use EzPhp\Ai\Response\AiChunk;
use EzPhp\Ai\Response\AiStream;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Http\Sse\SseEvent;
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

    public function testToSseEventsEmitsOneTokenEventPerNonEmptyChunkAndAFinalDoneEvent(): void
    {
        $stream = new AiStream($this->makeGenerator([
            new AiChunk('Hello'),
            new AiChunk(''),
            new AiChunk(" wor\nld"),
            new AiChunk('', FinishReason::STOP),
        ]));

        $frames = array_map(
            static fn (SseEvent $e): string => $e->toString(),
            iterator_to_array($stream->toSseEvents(), false),
        );

        $this->assertSame(
            [
                "event: token\ndata: {\"content\":\"Hello\"}\n\n",
                "event: token\ndata: {\"content\":\" wor\\nld\"}\n\n",
                "event: done\ndata: {\"finish_reason\":\"stop\"}\n\n",
            ],
            $frames,
        );
    }

    public function testToSseEventsDoneEventCarriesNullWhenNoFinishReasonWasSent(): void
    {
        $stream = new AiStream($this->makeGenerator([new AiChunk('x')]));

        $frames = iterator_to_array($stream->toSseEvents(), false);
        $last = end($frames);

        $this->assertInstanceOf(SseEvent::class, $last);
        $this->assertSame("event: done\ndata: {\"finish_reason\":null}\n\n", $last->toString());
    }

    public function testToSseEventsOnAPartlyConsumedStreamContinuesInsteadOfThrowing(): void
    {
        $generator = $this->makeGenerator([new AiChunk('a'), new AiChunk('b')]);
        $generator->current(); // start the generator, as a consumer that peeked would
        $generator->next();

        $frames = array_map(
            static fn (SseEvent $e): string => $e->toString(),
            iterator_to_array((new AiStream($generator))->toSseEvents(), false),
        );

        $this->assertSame(
            ["event: token\ndata: {\"content\":\"b\"}\n\n", "event: done\ndata: {\"finish_reason\":null}\n\n"],
            $frames,
        );
    }
}
