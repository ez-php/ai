<?php

declare(strict_types=1);

namespace EzPhp\Ai;

use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;

/**
 * Contract for all AI completion drivers.
 *
 * @package EzPhp\Ai
 */
interface AiClientInterface
{
    /**
     * Send a completion request and return the model's response.
     *
     * @param AiRequest $request
     *
     * @return AiResponse
     *
     * @throws AiRequestException On HTTP-level or API error.
     */
    public function complete(AiRequest $request): AiResponse;
}
