<?php

declare(strict_types=1);

namespace Tests\Ai\Support;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;

/**
 * Test double that throws AiRequestException a fixed number of times, then returns a response.
 *
 * @package Tests\Ai\Support
 */
final class CountingFailClient implements AiClientInterface
{
    private int $calls = 0;

    /**
     * @param int    $failTimes      How many times to throw before succeeding.
     * @param int    $failStatusCode HTTP status code of the thrown exception.
     * @param string $failBody       Response body of the thrown exception.
     */
    public function __construct(
        private readonly int $failTimes,
        private readonly int $failStatusCode,
        private readonly AiResponse $successResponse,
        private readonly string $failBody = '{}',
    ) {
    }

    /**
     * @param AiRequest $request
     *
     * @return AiResponse
     */
    public function complete(AiRequest $request): AiResponse
    {
        $this->calls++;

        if ($this->calls <= $this->failTimes) {
            throw AiRequestException::fromResponse($this->failStatusCode, $this->failBody);
        }

        return $this->successResponse;
    }

    /**
     * @return int
     */
    public function callCount(): int
    {
        return $this->calls;
    }
}
