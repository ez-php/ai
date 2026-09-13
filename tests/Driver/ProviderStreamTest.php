<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiStreamException;
use EzPhp\Ai\Driver\ProviderStream;
use EzPhp\HttpClient\HttpStream;
use EzPhp\HttpClient\HttpStreamException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Ai\TestCase;

#[CoversClass(ProviderStream::class)]
#[UsesClass(AiStreamException::class)]
final class ProviderStreamTest extends TestCase
{
    public function testDecodesMessagesFromTheStream(): void
    {
        $body = "data: hello\n\ndata: world\n\n";
        $stream = HttpStream::fake([$body]);

        $messages = iterator_to_array(ProviderStream::messages($stream));

        $this->assertCount(2, $messages);
        $this->assertSame('hello', $messages[0]->data());
        $this->assertSame('world', $messages[1]->data());
    }

    public function testDecodesAMessageSplitAcrossChunkBoundaries(): void
    {
        $body = 'data: hel';
        $rest = "lo world\n\n";
        $stream = HttpStream::fake([$body, $rest]);

        $messages = iterator_to_array(ProviderStream::messages($stream));

        $this->assertCount(1, $messages);
        $this->assertSame('hello world', $messages[0]->data());
    }

    public function testTransportFailureIsWrappedInAiStreamException(): void
    {
        $cause = new HttpStreamException('Connection reset by peer');
        $stream = HttpStream::fake(["data: partial\n\n", $cause]);

        $iterator = ProviderStream::messages($stream);

        $this->assertSame('partial', $iterator->current()->data());

        try {
            $iterator->next();
            $this->fail('Expected AiStreamException.');
        } catch (AiStreamException $e) {
            $this->assertSame('Connection reset by peer', $e->getMessage());
            $this->assertSame($cause, $e->getPrevious());
        }
    }
}
