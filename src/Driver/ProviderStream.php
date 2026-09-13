<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

use EzPhp\Ai\AiStreamException;
use EzPhp\HttpClient\HttpStream;
use EzPhp\HttpClient\HttpStreamException;
use EzPhp\HttpClient\Sse\SseDecoder;
use EzPhp\HttpClient\Sse\SseMessage;
use Generator;

/**
 * Decodes a provider's SSE response and converts transport failures.
 *
 * Shared by the streaming drivers so each one only maps event payloads.
 *
 * @internal
 *
 * @package EzPhp\Ai\Driver
 */
final class ProviderStream
{
    /**
     * @param HttpStream $stream
     *
     * @return Generator<int, SseMessage, void, void>
     * @throws AiStreamException When the transport fails mid-stream.
     */
    public static function messages(HttpStream $stream): Generator
    {
        try {
            yield from SseDecoder::decode($stream);
        } catch (HttpStreamException $e) {
            throw new AiStreamException($e->getMessage(), null, $e);
        }
    }
}
