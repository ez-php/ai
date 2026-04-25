<?php

declare(strict_types=1);

namespace EzPhp\Ai\Response;

use EzPhp\Ai\Tool\ToolCall;

/**
 * Immutable value object representing a completed AI provider response.
 *
 * Constructed exclusively by driver implementations after a successful API call.
 *
 * @package EzPhp\Ai\Response
 */
final readonly class AiResponse
{
    /**
     * @param string         $content      The generated text content.
     * @param FinishReason   $finishReason Why the model stopped generating.
     * @param TokenUsage     $usage        Token counts for this request/response pair.
     * @param string         $rawBody      The raw JSON response body from the provider.
     * @param list<ToolCall> $toolCalls    Tool calls requested by the model (when finishReason is TOOL_CALL).
     */
    public function __construct(
        private string $content,
        private FinishReason $finishReason,
        private TokenUsage $usage,
        private string $rawBody,
        private array $toolCalls = [],
    ) {
    }

    /**
     * @return string
     */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * @return FinishReason
     */
    public function finishReason(): FinishReason
    {
        return $this->finishReason;
    }

    /**
     * @return TokenUsage
     */
    public function usage(): TokenUsage
    {
        return $this->usage;
    }

    /**
     * @return string
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Tool calls requested by the model. Non-empty when finishReason is TOOL_CALL.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * Whether the model requested one or more tool calls.
     *
     * @return bool
     */
    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * Whether the model reached a natural stopping point (finish reason is STOP).
     *
     * @return bool
     */
    public function isComplete(): bool
    {
        return $this->finishReason === FinishReason::STOP;
    }

    /**
     * Decode the response content as a JSON object.
     *
     * Strips markdown code fences if present. Throws JsonException when the content
     * is not valid JSON or decodes to an array (list) instead of an object.
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    public function json(): array
    {
        $decoded = json_decode($this->extractJson(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \JsonException('AI response content is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Decode the response content as a JSON array.
     *
     * Strips markdown code fences if present. Throws JsonException when the content
     * is not valid JSON or decodes to an object instead of an array.
     *
     * @return list<mixed>
     *
     * @throws \JsonException
     */
    public function jsonArray(): array
    {
        $decoded = json_decode($this->extractJson(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \JsonException('AI response content is not a JSON array.');
        }

        /** @var list<mixed> $decoded */
        return $decoded;
    }

    /**
     * Strip markdown code fences from the content if present.
     *
     * @return string
     */
    private function extractJson(): string
    {
        $trimmed = trim($this->content);

        if (!str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $lines = explode("\n", $trimmed);
        array_shift($lines);

        if (end($lines) === '```') {
            array_pop($lines);
        }

        return trim(implode("\n", $lines));
    }
}
