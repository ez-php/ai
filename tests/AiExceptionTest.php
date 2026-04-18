<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Ai\AiException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(AiException::class)]
final class AiExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $exception = new AiException('something went wrong');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('something went wrong', $exception->getMessage());
    }

    public function testCanBeThrown(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('ai error');

        throw new AiException('ai error');
    }
}
