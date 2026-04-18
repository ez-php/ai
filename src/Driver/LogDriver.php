<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

use Closure;
use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;

/**
 * A driver decorator that logs every request and response before delegating to an inner driver.
 *
 * The logger callable follows PSR-3 conventions: `function(string $level, string $message, array $context): void`.
 *
 * @package EzPhp\Ai\Driver
 */
final class LogDriver implements AiClientInterface
{
    /**
     * @param AiClientInterface                                    $inner  The driver to delegate actual completion to.
     * @param Closure(string, string, array<string, mixed>): void  $logger PSR-3-style logging closure.
     */
    public function __construct(
        private readonly AiClientInterface $inner,
        private readonly Closure $logger,
    ) {
    }

    /**
     * Log the outgoing request, delegate to the inner driver, then log the response.
     *
     * @param AiRequest $request
     *
     * @return AiResponse
     */
    public function complete(AiRequest $request): AiResponse
    {
        ($this->logger)('debug', 'ai.request', [
            'message_count' => count($request->messages()),
            'model' => $request->model(),
            'max_tokens' => $request->maxTokens(),
        ]);

        $response = $this->inner->complete($request);

        ($this->logger)('debug', 'ai.response', [
            'finish_reason' => $response->finishReason()->value,
            'content_length' => strlen($response->content()),
            'input_tokens' => $response->usage()->inputTokens(),
            'output_tokens' => $response->usage()->outputTokens(),
        ]);

        return $response;
    }
}
