<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\Driver\NullDriver;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Response\TokenUsage;
use Tests\TestCase;

/**
 * @covers \EzPhp\Ai\Driver\NullDriver
 */
final class NullDriverTest extends TestCase
{
    public function testImplementsAiClientInterface(): void
    {
        $driver = NullDriver::withContent('hello');

        $this->assertInstanceOf(AiClientInterface::class, $driver);
    }

    public function testWithContentReturnsStopResponse(): void
    {
        $driver = NullDriver::withContent('canned');
        $response = $driver->complete(AiRequest::make('ping'));

        $this->assertSame('canned', $response->content());
        $this->assertSame(FinishReason::STOP, $response->finishReason());
        $this->assertSame(0, $response->usage()->inputTokens());
        $this->assertSame(0, $response->usage()->outputTokens());
        $this->assertSame('{}', $response->rawBody());
    }

    public function testConstructorAcceptsCustomResponse(): void
    {
        $custom = new AiResponse('custom', FinishReason::LENGTH, new TokenUsage(10, 20), '{"raw":true}');
        $driver = new NullDriver($custom);
        $response = $driver->complete(AiRequest::make('anything'));

        $this->assertSame($custom, $response);
    }

    public function testAlwaysReturnsSameResponseRegardlessOfRequest(): void
    {
        $driver = NullDriver::withContent('fixed');

        $r1 = $driver->complete(AiRequest::make('first'));
        $r2 = $driver->complete(AiRequest::make('second'));

        $this->assertSame($r1, $r2);
    }

    public function testRequestIsIgnored(): void
    {
        $driver = NullDriver::withContent('result');
        $request = AiRequest::make('anything')
            ->withModel('gpt-4o')
            ->withTemperature(0.9)
            ->withMaxTokens(500);

        $response = $driver->complete($request);

        $this->assertSame('result', $response->content());
    }
}
