<?php

declare(strict_types=1);

namespace EzPhp\Ai\Response;

use Generator;
use IteratorAggregate;

/**
 * An iterable value object that yields AiChunk objects from a streaming completion.
 *
 * Backed by a PHP Generator created by the driver. Generators are one-shot —
 * iterating the stream a second time yields nothing.
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
}
