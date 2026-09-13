<?php

declare(strict_types=1);

namespace EzPhp\Ai\Response;

use EzPhp\Http\Sse\SseEvent;
use Generator;
use IteratorAggregate;

/**
 * An iterable value object that yields AiChunk objects from a streaming completion.
 *
 * Backed by a PHP Generator created by the driver. Generators are one-shot —
 * iterating the stream a second time yields nothing.
 *
 * Chunks are produced while the provider is still generating. Iteration
 * throws AiStreamException when the transfer fails, the provider reports an
 * error, or the stream ends without the provider's completion signal.
 *
 * Usage:
 *
 *   foreach ($stream as $chunk) {
 *       echo $chunk->content();
 *   }
 *
 * Or collect all content at once:
 *
 *   $fullText = $stream->collect();
 *
 * @implements IteratorAggregate<int, AiChunk>
 *
 * @package EzPhp\Ai\Response
 */
final class AiStream implements IteratorAggregate
{
    /**
     * @param Generator<int, AiChunk, void, void> $generator
     */
    public function __construct(private readonly Generator $generator)
    {
    }

    /**
     * @return Generator<int, AiChunk, void, void>
     */
    public function getIterator(): Generator
    {
        return $this->generator;
    }

    /**
     * Consume the stream and return the concatenated content of all chunks.
     *
     * @return string
     */
    public function collect(): string
    {
        $content = '';

        while ($this->generator->valid()) {
            $content .= $this->generator->current()->content();
            $this->generator->next();
        }

        return $content;
    }

    /**
     * Map the stream to Server-Sent Events for StreamedResponse::sse().
     *
     * Emits `event: token` with `{"content": …}` per chunk that has content, then
     * one `event: done` with `{"finish_reason": …}` (null when the provider sent
     * none). Payloads are JSON so newlines in model output cannot break SSE
     * framing.
     *
     * Single-use, like the stream itself: create the AI call in the controller
     * and return `StreamedResponse::sse(fn () => $stream->toSseEvents())`.
     *
     * @throws \JsonException
     *
     * @return Generator<int, SseEvent, void, void>
     */
    public function toSseEvents(): Generator
    {
        $finishReason = null;

        // valid()/next() rather than foreach, for the same reason as collect():
        // foreach rewinds, and rewinding an already-started generator throws.
        while ($this->generator->valid()) {
            $chunk = $this->generator->current();

            if ($chunk->content() !== '') {
                yield new SseEvent(json_encode(['content' => $chunk->content()], JSON_THROW_ON_ERROR), 'token');
            }

            if ($chunk->finishReason() !== null) {
                $finishReason = $chunk->finishReason()->value;
            }

            $this->generator->next();
        }

        yield new SseEvent(json_encode(['finish_reason' => $finishReason], JSON_THROW_ON_ERROR), 'done');
    }
}
