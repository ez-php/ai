<?php

declare(strict_types=1);

namespace Tests\Ai\Response;

use EzPhp\Ai\Response\AiChunk;
use EzPhp\Ai\Response\FinishReason;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Response\AiChunk
 * @uses   \EzPhp\Ai\Response\FinishReason
 */
final class AiChunkTest extends TestCase
{
    public function testContentIsReturned(): void
    {
        $chunk = new AiChunk('Hello');

        $this->assertSame('Hello', $chunk->content());
    }

    public function testFinishReasonIsNullByDefault(): void
    {
        $chunk = new AiChunk('text');

        $this->assertNull($chunk->finishReason());
    }

    public function testFinishReasonIsReturned(): void
    {
        $chunk = new AiChunk('', FinishReason::STOP);

        $this->assertSame(FinishReason::STOP, $chunk->finishReason());
    }

    public function testIsFinalReturnsFalseWhenNoFinishReason(): void
    {
        $this->assertFalse((new AiChunk('delta'))->isFinal());
    }

    public function testIsFinalReturnsTrueWhenFinishReasonSet(): void
    {
        $this->assertTrue((new AiChunk('', FinishReason::STOP))->isFinal());
    }

    public function testEmptyContentIsAllowed(): void
    {
        $chunk = new AiChunk('');

        $this->assertSame('', $chunk->content());
    }

    public function testAllFinishReasonVariants(): void
    {
        foreach (FinishReason::cases() as $reason) {
            $chunk = new AiChunk('x', $reason);
            $this->assertSame($reason, $chunk->finishReason());
            $this->assertTrue($chunk->isFinal());
        }
    }
}
