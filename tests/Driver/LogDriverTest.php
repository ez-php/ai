<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\Driver\LogDriver;
use EzPhp\Ai\Driver\NullDriver;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\FinishReason;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\LogDriver
 */
final class LogDriverTest extends TestCase
{
    public function testImplementsAiClientInterface(): void
    {
        $driver = new LogDriver(NullDriver::withContent('x'), static function (): void {
        });

        $this->assertInstanceOf(AiClientInterface::class, $driver);
    }

    public function testDelegatesCompleteToInnerDriver(): void
    {
        $inner = NullDriver::withContent('delegated');
        $driver = new LogDriver($inner, static function (): void {
        });

        $response = $driver->complete(AiRequest::make('hello'));

        $this->assertSame('delegated', $response->content());
    }

    public function testLogsRequestAndResponseEntries(): void
    {
        /** @var list<array{string, string, array<string, mixed>}> $log */
        $log = [];
        $logger = static function (string $level, string $message, array $context) use (&$log): void {
            $log[] = [$level, $message, $context];
        };

        $driver = new LogDriver(NullDriver::withContent('hi'), $logger);
        $driver->complete(AiRequest::make('test'));

        $this->assertCount(2, $log);
        $this->assertSame(['debug', 'ai.request'], [$log[0][0], $log[0][1]]);
        $this->assertSame(['debug', 'ai.response'], [$log[1][0], $log[1][1]]);
    }

    public function testRequestContextContainsMessageCount(): void
    {
        /** @var list<array{string, string, array<string, mixed>}> $log */
        $log = [];
        $logger = static function (string $level, string $message, array $context) use (&$log): void {
            $log[] = [$level, $message, $context];
        };

        $driver = new LogDriver(NullDriver::withContent('ok'), $logger);
        $driver->complete(AiRequest::make('one message'));

        $this->assertArrayHasKey('message_count', $log[0][2]);
        $this->assertSame(1, $log[0][2]['message_count']);
    }

    public function testResponseContextContainsFinishReasonAndTokens(): void
    {
        /** @var list<array{string, string, array<string, mixed>}> $log */
        $log = [];
        $logger = static function (string $level, string $message, array $context) use (&$log): void {
            $log[] = [$level, $message, $context];
        };

        $driver = new LogDriver(NullDriver::withContent('result'), $logger);
        $driver->complete(AiRequest::make('hi'));

        $ctx = $log[1][2];
        $this->assertSame(FinishReason::STOP->value, $ctx['finish_reason']);
        $this->assertArrayHasKey('input_tokens', $ctx);
        $this->assertArrayHasKey('output_tokens', $ctx);
        $this->assertArrayHasKey('content_length', $ctx);
    }

    public function testReturnsInnerDriverResponse(): void
    {
        $inner = NullDriver::withContent('passthrough');
        $driver = new LogDriver($inner, static function (): void {
        });

        $response = $driver->complete(AiRequest::make('check'));

        $this->assertSame('passthrough', $response->content());
        $this->assertTrue($response->isComplete());
    }
}
