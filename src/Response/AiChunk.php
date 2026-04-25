<?php

declare(strict_types=1);

namespace EzPhp\Ai\Response;

/**
 * An immutable partial response yielded by a streaming completion.
 *
 * Most chunks carry a content delta and no finish reason.
 * The final chunk in a stream carries a finish reason (and may have empty content).
 *
 * @package EzPhp\Ai\Response
 */
final readonly class AiChunk
{
    /**
     * @param string            $content      Partial content delta for this chunk.
     * @param FinishReason|null $finishReason Set only on the final chunk; null for intermediate chunks.
     */
    public function __construct(
        private string $content,
        private ?FinishReason $finishReason = null,
    ) {
    }

    /**
     * @return string
     */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * @return FinishReason|null
     */
    public function finishReason(): ?FinishReason
    {
        return $this->finishReason;
    }

    /**
     * Whether this chunk is the final one in the stream.
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return $this->finishReason !== null;
    }
}
