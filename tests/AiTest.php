<?php

declare(strict_types=1);

namespace Tests\Ai;

use EzPhp\Ai\Ai;
use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Driver\NullDriver;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Response\TokenUsage;

/**
 * @covers \EzPhp\Ai\Ai
 * @uses   \EzPhp\Ai\Driver\NullDriver
 * @uses   \EzPhp\Ai\Request\AiRequest
 * @uses   \EzPhp\Ai\Response\AiResponse
 * @uses   \EzPhp\Ai\Response\TokenUsage
 * @uses   \EzPhp\Ai\Response\FinishReason
 * @uses   \EzPhp\Ai\Message\AiMessage
 * @uses   \EzPhp\Ai\Message\Role
 * @uses   \EzPhp\Ai\AiException
 * @uses   \EzPhp\Ai\AiRequestException
 */
final class AiTest extends TestCase
{
    protected function setUp(): void
    {
        Ai::resetClient();
    }

    protected function tearDown(): void
    {
        Ai::resetClient();
    }

    // ─── Client management ────────────────────────────────────────────────────

    public function testGetClientReturnsNullDriverWhenNoneSet(): void
    {
        $client = Ai::getClient();

        $this->assertInstanceOf(AiClientInterface::class, $client);
        $this->assertInstanceOf(NullDriver::class, $client);
    }

    public function testSetClientReplacesActiveClient(): void
    {
        $stub = NullDriver::withContent('stub response');
        Ai::setClient($stub);

        $this->assertSame($stub, Ai::getClient());
    }

    public function testResetClientNullsActiveClient(): void
    {
        Ai::setClient(NullDriver::withContent('x'));
        Ai::resetClient();

        $this->assertInstanceOf(NullDriver::class, Ai::getClient());
        // After reset a new NullDriver is created lazily — not the same instance
        $this->assertNotSame(NullDriver::withContent('x'), Ai::getClient());
    }

    // ─── complete() ───────────────────────────────────────────────────────────

    public function testCompleteForwardsToActiveClient(): void
    {
        Ai::setClient(NullDriver::withContent('forwarded'));

        $response = Ai::complete(AiRequest::make('hi'));

        $this->assertSame('forwarded', $response->content());
    }

    public function testCompleteUsesLazyNullDriverWhenNoClientSet(): void
    {
        $response = Ai::complete(AiRequest::make('hi'));

        $this->assertInstanceOf(AiResponse::class, $response);
        $this->assertSame(FinishReason::STOP, $response->finishReason());
    }

    public function testCompleteReturnsAiResponseFromInjectedClient(): void
    {
        $expected = new AiResponse('custom', FinishReason::LENGTH, new TokenUsage(5, 10), '{}');
        $stub = new class ($expected) implements AiClientInterface {
            public function __construct(private readonly AiResponse $response)
            {
            }

            public function complete(AiRequest $request): AiResponse
            {
                return $this->response;
            }
        };

        Ai::setClient($stub);
        $result = Ai::complete(AiRequest::make('ping'));

        $this->assertSame('custom', $result->content());
        $this->assertSame(FinishReason::LENGTH, $result->finishReason());
        $this->assertSame(5, $result->usage()->inputTokens());
    }

    public function testCompletePropagatesTohrownAiRequestException(): void
    {
        $throwing = new class () implements AiClientInterface {
            public function complete(AiRequest $request): AiResponse
            {
                throw new AiRequestException('boom', 500, 'error body');
            }
        };

        Ai::setClient($throwing);

        $this->expectException(AiRequestException::class);
        Ai::complete(AiRequest::make('hi'));
    }

    public function testMultipleSetClientCallsReplaceEachOther(): void
    {
        $first = NullDriver::withContent('first');
        $second = NullDriver::withContent('second');

        Ai::setClient($first);
        Ai::setClient($second);

        $this->assertSame($second, Ai::getClient());
    }
}
