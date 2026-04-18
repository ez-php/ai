<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Message\ContentPart;
use EzPhp\Ai\Message\ContentPartType;
use EzPhp\Ai\Message\Role;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Response\TokenUsage;
use EzPhp\HttpClient\HttpClient;

/**
 * AI completion driver for the Anthropic Messages API.
 *
 * Calls POST /v1/messages. Anthropic places system instructions in a top-level
 * `system` field — the driver extracts AiRequest::systemPrompt() accordingly.
 * System-role messages in the messages array are filtered out and prepended to
 * that field.
 *
 * @package EzPhp\Ai\Driver
 */
final class AnthropicDriver implements AiClientInterface
{
    private const int DEFAULT_MAX_TOKENS = 1024;

    /**
     * @param HttpClient      $http   Injected HTTP client; use FakeTransport in tests.
     * @param AnthropicConfig $config Driver configuration.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly AnthropicConfig $config,
    ) {
    }

    /**
     * Send a completion request to Anthropic and return the parsed response.
     *
     * @param AiRequest $request
     *
     * @return AiResponse
     *
     * @throws AiRequestException On HTTP error or malformed response body.
     */
    public function complete(AiRequest $request): AiResponse
    {
        $body = $this->buildBody($request);
        $url = AnthropicConfig::BASE_URL . '/v1/messages';

        $httpResponse = $this->http
            ->post($url)
            ->withHeaders([
                'x-api-key' => $this->config->apiKey(),
                'anthropic-version' => $this->config->apiVersion(),
                'Content-Type' => 'application/json',
            ])
            ->withBody((string) json_encode($body))
            ->send();

        if (!$httpResponse->ok()) {
            throw AiRequestException::fromResponse($httpResponse->status(), $httpResponse->body());
        }

        return $this->parseResponse($httpResponse->body());
    }

    /**
     * Build the Anthropic request body from an AiRequest.
     *
     * System instructions from AiRequest::systemPrompt() and any system-role
     * messages are merged into the top-level `system` field.
     *
     * @param AiRequest $request
     *
     * @return array<string, mixed>
     */
    private function buildBody(AiRequest $request): array
    {
        $systemParts = [];

        if ($request->systemPrompt() !== null) {
            $systemParts[] = $request->systemPrompt();
        }

        $messages = [];

        foreach ($request->messages() as $message) {
            if ($message->role() === Role::SYSTEM) {
                $systemParts[] = $message->textContent();
                continue;
            }

            $messages[] = $this->serializeMessage($message);
        }

        /** @var array<string, mixed> $body */
        $body = [
            'model' => $request->model() ?? $this->config->model(),
            'max_tokens' => $request->maxTokens() ?? self::DEFAULT_MAX_TOKENS,
            'messages' => $messages,
        ];

        if ($systemParts !== []) {
            $body['system'] = implode("\n\n", $systemParts);
        }

        if ($request->temperature() !== null) {
            $body['temperature'] = $request->temperature();
        }

        return $body;
    }

    /**
     * Serialize an AiMessage to the Anthropic messages array entry format.
     *
     * @param AiMessage $message
     *
     * @return array<string, mixed>
     */
    private function serializeMessage(AiMessage $message): array
    {
        $content = $message->content();

        if (is_string($content)) {
            return ['role' => $message->role()->value, 'content' => $content];
        }

        $parts = [];

        foreach ($content as $part) {
            $parts[] = $this->serializePart($part);
        }

        return ['role' => $message->role()->value, 'content' => $parts];
    }

    /**
     * Serialize a ContentPart to the Anthropic content part format.
     *
     * @param ContentPart $part
     *
     * @return array<string, mixed>
     */
    private function serializePart(ContentPart $part): array
    {
        if ($part->type() === ContentPartType::IMAGE_URL) {
            return ['type' => 'image', 'source' => ['type' => 'url', 'url' => $part->content()]];
        }

        return ['type' => 'text', 'text' => $part->content()];
    }

    /**
     * Parse an Anthropic JSON response body into an AiResponse.
     *
     * @param string $rawBody
     *
     * @return AiResponse
     *
     * @throws AiRequestException When the response body is missing required fields.
     */
    private function parseResponse(string $rawBody): AiResponse
    {
        /** @var mixed $decoded */
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            throw new AiRequestException('Anthropic returned a non-JSON response body', 0, $rawBody);
        }

        $contentBlocks = $decoded['content'] ?? null;

        if (!is_array($contentBlocks) || $contentBlocks === []) {
            throw new AiRequestException('Anthropic response missing content array', 0, $rawBody);
        }

        $content = $this->extractTextContent($contentBlocks, $rawBody);

        $finishReason = $this->mapFinishReason(
            is_string($decoded['stop_reason'] ?? null) ? $decoded['stop_reason'] : '',
        );

        $usageRaw = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $inputTokens = is_int($usageRaw['input_tokens'] ?? null) ? $usageRaw['input_tokens'] : 0;
        $outputTokens = is_int($usageRaw['output_tokens'] ?? null) ? $usageRaw['output_tokens'] : 0;

        return new AiResponse($content, $finishReason, new TokenUsage($inputTokens, $outputTokens), $rawBody);
    }

    /**
     * Extract the text content string from Anthropic's content block array.
     *
     * @param array<mixed> $blocks
     * @param string       $rawBody
     *
     * @return string
     *
     * @throws AiRequestException When no text block is present.
     */
    private function extractTextContent(array $blocks, string $rawBody): string
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                return $block['text'];
            }
        }

        throw new AiRequestException('Anthropic response contains no text content block', 0, $rawBody);
    }

    /**
     * Map an Anthropic stop_reason string to a FinishReason enum case.
     *
     * @param string $reason
     *
     * @return FinishReason
     */
    private function mapFinishReason(string $reason): FinishReason
    {
        return match ($reason) {
            'end_turn', 'stop_sequence' => FinishReason::STOP,
            'max_tokens' => FinishReason::LENGTH,
            'tool_use' => FinishReason::TOOL_CALL,
            default => FinishReason::ERROR,
        };
    }
}
