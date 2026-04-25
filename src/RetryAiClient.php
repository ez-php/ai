<?php

declare(strict_types=1);

namespace EzPhp\Ai;

use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;
use InvalidArgumentException;

/**
 * A decorator that retries failed AI requests with exponential backoff.
 *
 * Retryable conditions: HTTP 429 (rate limit) and 5xx server errors.
 * Non-retryable 4xx errors (except 429) are re-thrown immediately.
 * On 429, the response body is inspected for a `retry_after` field (seconds);
 * if found it overrides the exponential backoff delay.
 *
 * @package EzPhp\Ai
 */
final class RetryAiClient implements AiClientInterface
{
    /**
     * @param AiClientInterface $inner       The wrapped client.
     * @param int               $maxAttempts Maximum total attempts (including the first). Minimum 1.
     * @param int               $baseDelayMs Base delay in milliseconds for exponential backoff.
     *
     * @throws InvalidArgumentException When maxAttempts < 1 or baseDelayMs < 0.
     */
    public function __construct(
        private readonly AiClientInterface $inner,
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 500,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1.');
        }

        if ($baseDelayMs < 0) {
            throw new InvalidArgumentException('baseDelayMs must be non-negative.');
        }
    }

    /**
     * Complete the request, retrying on transient errors up to maxAttempts times.
     *
     * @param AiRequest $request
     *
     * @return AiResponse
     *
     * @throws AiRequestException When all attempts fail or a non-retryable error occurs.
     */
    public function complete(AiRequest $request): AiResponse
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                return $this->inner->complete($request);
            } catch (AiRequestException $e) {
                if ($this->isNonRetryable($e)) {
                    throw $e;
                }

                $lastException = $e;

                if ($attempt < $this->maxAttempts) {
                    $delayMs = $this->resolveDelayMs($e, $attempt);

                    if ($delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                }
            }
        }

        throw $lastException ?? new AiRequestException(
            sprintf('AI request failed after %d attempt(s).', $this->maxAttempts),
        );
    }

    /**
     * @param AiRequestException $e
     *
     * @return bool
     */
    private function isNonRetryable(AiRequestException $e): bool
    {
        $status = $e->statusCode();

        return $status >= 400 && $status < 500 && $status !== 429;
    }

    /**
     * @param AiRequestException $e
     * @param int                $attempt 1-based attempt number that just failed.
     *
     * @return int Delay in milliseconds.
     */
    private function resolveDelayMs(AiRequestException $e, int $attempt): int
    {
        if ($e->statusCode() === 429) {
            $retryAfter = $this->parseRetryAfterSeconds($e->responseBody());

            if ($retryAfter !== null) {
                return $retryAfter * 1000;
            }
        }

        return $this->baseDelayMs * (int) (2 ** ($attempt - 1));
    }

    /**
     * @param string $body
     *
     * @return int|null Seconds to wait, or null if not parseable.
     */
    private function parseRetryAfterSeconds(string $body): ?int
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return null;
        }

        $retryAfter = $decoded['retry_after'] ?? null;

        if (!is_numeric($retryAfter)) {
            return null;
        }

        return (int) $retryAfter;
    }
}
