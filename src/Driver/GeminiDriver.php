<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Message\ContentPart;
use EzPhp\Ai\Message\ContentPartType;
use EzPhp\Ai\Message\Role;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiChunk;
use EzPhp\Ai\Response\AiResponse;
use EzPhp\Ai\Response\AiStream;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Response\TokenUsage;
use EzPhp\Ai\StreamingAiClientInterface;
use EzPhp\Ai\Tool\ToolCall;
use EzPhp\HttpClient\HttpClient;
use Generator;

/**
 * AI completion driver for the Google Gemini generateContent REST API.
 *
 * Calls POST /v1beta/models/{model}:generateContent?key={apiKey}.
 * Messages are mapped to Gemini's `contents[].parts[]` structure.
 * System instructions (from AiRequest::systemPrompt() and system-role messages)
 * are placed in the top-level `systemInstruction` field.
 * Assistant messages use Gemini's "model" role, not "assistant".
 *
 * @package EzPhp\Ai\Driver
 */
final class GeminiDriver implements StreamingAiClientInterface
{
    /**
     * @param HttpClient   $http   Injected HTTP client; use FakeTransport in tests.
     * @param GeminiConfig $config Driver configuration.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly GeminiConfig $config,
    ) {
    }

    /**
     * Send a completion request to Gemini and return the parsed response.
     *
     * @param AiRequest $request
     *
     * @return AiResponse
     *
     * @throws AiRequestException On HTTP error or malformed response body.
     */
    public function complete(AiRequest $request): AiResponse
    {
        $model = $request->model() ?? $this->config->model();
        $url = sprintf(
            '%s/v1beta/models/%s:generateContent?key=%s',
            GeminiConfig::BASE_URL,
            $model,
            $this->config->apiKey(),
        );

        $body = $this->buildBody($request);

        $httpResponse = $this->http
            ->post($url)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withBody((string) json_encode($body))
            ->send();

        if (!$httpResponse->ok()) {
            throw AiRequestException::fromResponse($httpResponse->status(), $httpResponse->body());
        }

        return $this->parseResponse($httpResponse->body());
    }

    /**
     * Build the Gemini request body from an AiRequest.
     *
     * System instructions from AiRequest::systemPrompt() and any system-role
     * messages are merged into the top-level `systemInstruction` field.
     * Assistant messages are mapped to Gemini's "model" role.
     *
     * @param AiRequest $request
     *
     * @return array<string, mixed>
     */
    private function buildBody(AiRequest $request): array
    {
        $systemParts = [];

        if ($request->systemPrompt() !== null) {
            $systemParts[] = ['text' => $request->systemPrompt()];
        }

        $contents = [];

        foreach ($request->messages() as $message) {
            if ($message->role() === Role::SYSTEM) {
                $systemParts[] = ['text' => $message->textContent()];
                continue;
            }

            $contents[] = $this->serializeMessage($message);
        }

        /** @var array<string, mixed> $body */
        $body = ['contents' => $contents];

        if ($systemParts !== []) {
            $body['systemInstruction'] = ['parts' => $systemParts];
        }

        $generationConfig = [];

        if ($request->temperature() !== null) {
            $generationConfig['temperature'] = $request->temperature();
        }

        if ($request->maxTokens() !== null) {
            $generationConfig['maxOutputTokens'] = $request->maxTokens();
        }

        if ($generationConfig !== []) {
            $body['generationConfig'] = $generationConfig;
        }

        if ($request->hasTools()) {
            $declarations = [];

            foreach ($request->tools() as $tool) {
                $declarations[] = [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters() !== [] ? $tool->parameters() : ['type' => 'object', 'properties' => []],
                ];
            }

            $body['tools'] = [['function_declarations' => $declarations]];
        }

        return $body;
    }

    /**
     * Serialize an AiMessage to the Gemini contents entry format.
     *
     * Tool result messages (Role::TOOL) become user-role messages with
     * functionResponse parts, as required by the Gemini API.
     *
     * @param AiMessage $message
     *
     * @return array<string, mixed>
     */
    private function serializeMessage(AiMessage $message): array
    {
        if ($message->role() === Role::TOOL) {
            return [
                'role' => 'user',
                'parts' => [[
                    'functionResponse' => [
                        'name' => $message->toolCallId(),
                        'response' => ['result' => $message->textContent()],
                    ],
                ]],
            ];
        }

        if ($message->role() === Role::ASSISTANT && $message->toolCalls() !== []) {
            $parts = [];

            foreach ($message->toolCalls() as $toolCall) {
                $parts[] = ['functionCall' => ['name' => $toolCall->name(), 'args' => $toolCall->arguments()]];
            }

            return ['role' => 'model', 'parts' => $parts];
        }

        $role = $message->role() === Role::ASSISTANT ? 'model' : 'user';
        $content = $message->content();

        if (is_string($content)) {
            return ['role' => $role, 'parts' => [['text' => $content]]];
        }

        $parts = [];

        foreach ($content as $part) {
            $parts[] = $this->serializePart($part);
        }

        return ['role' => $role, 'parts' => $parts];
    }

    /**
     * Serialize a ContentPart to the Gemini parts entry format.
     *
     * @param ContentPart $part
     *
     * @return array<string, mixed>
     */
    private function serializePart(ContentPart $part): array
    {
        if ($part->type() === ContentPartType::IMAGE_URL) {
            return ['fileData' => ['fileUri' => $part->content(), 'mimeType' => 'image/jpeg']];
        }

        return ['text' => $part->content()];
    }

    /**
     * Parse a Gemini JSON response body into an AiResponse.
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
            throw new AiRequestException('Gemini returned a non-JSON response body', 0, $rawBody);
        }

        $candidates = $decoded['candidates'] ?? null;

        if (!is_array($candidates) || !isset($candidates[0]) || !is_array($candidates[0])) {
            throw new AiRequestException('Gemini response missing candidates[0]', 0, $rawBody);
        }

        $candidate = $candidates[0];
        $contentBlock = $candidate['content'] ?? null;
        $parts = is_array($contentBlock) ? ($contentBlock['parts'] ?? null) : null;

        if (!is_array($parts) || $parts === []) {
            throw new AiRequestException('Gemini response missing candidates[0].content.parts', 0, $rawBody);
        }

        $toolCalls = $this->parseToolCalls($parts);
        $finishReason = $toolCalls !== []
            ? FinishReason::TOOL_CALL
            : $this->mapFinishReason(
                is_string($candidate['finishReason'] ?? null) ? $candidate['finishReason'] : '',
            );

        $content = '';

        if ($toolCalls === []) {
            $content = $this->extractText($parts, $rawBody);
        }

        $usageMeta = is_array($decoded['usageMetadata'] ?? null) ? $decoded['usageMetadata'] : [];
        $inputTokens = is_int($usageMeta['promptTokenCount'] ?? null) ? $usageMeta['promptTokenCount'] : 0;
        $outputTokens = is_int($usageMeta['candidatesTokenCount'] ?? null) ? $usageMeta['candidatesTokenCount'] : 0;

        return new AiResponse($content, $finishReason, new TokenUsage($inputTokens, $outputTokens), $rawBody, $toolCalls);
    }

    /**
     * Parse functionCall parts from a Gemini parts array.
     *
     * For Gemini, the function name serves as the call ID.
     *
     * @param array<mixed> $parts
     *
     * @return list<ToolCall>
     */
    private function parseToolCalls(array $parts): array
    {
        $toolCalls = [];

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            $fnCall = $part['functionCall'] ?? null;

            if (!is_array($fnCall)) {
                continue;
            }

            $name = is_string($fnCall['name'] ?? null) ? $fnCall['name'] : '';
            $args = is_array($fnCall['args'] ?? null) ? $fnCall['args'] : [];

            /** @var array<string, mixed> $args */
            $toolCalls[] = new ToolCall($name, $name, $args);
        }

        return $toolCalls;
    }

    /**
     * Extract concatenated text from a Gemini parts array.
     *
     * @param array<mixed> $parts
     * @param string       $rawBody
     *
     * @return string
     *
     * @throws AiRequestException When no text part is present.
     */
    private function extractText(array $parts, string $rawBody): string
    {
        $text = '';

        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        if ($text === '') {
            throw new AiRequestException('Gemini response contains no text in parts', 0, $rawBody);
        }

        return $text;
    }

    /**
     * Send a streaming completion request and return an AiStream of AiChunk objects.
     *
     * Uses the `streamGenerateContent` endpoint with `?alt=sse`. Each SSE event
     * is a full GenerateContentResponse JSON object; text is extracted from parts.
     *
     * @param AiRequest $request
     *
     * @return AiStream
     *
     * @throws AiRequestException On HTTP error or malformed response body.
     */
    public function stream(AiRequest $request): AiStream
    {
        $model = $request->model() ?? $this->config->model();
        $url = sprintf(
            '%s/v1beta/models/%s:streamGenerateContent?key=%s&alt=sse',
            GeminiConfig::BASE_URL,
            $model,
            $this->config->apiKey(),
        );

        $httpResponse = $this->http
            ->post($url)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withBody((string) json_encode($this->buildBody($request)))
            ->send();

        if (!$httpResponse->ok()) {
            throw AiRequestException::fromResponse($httpResponse->status(), $httpResponse->body());
        }

        return new AiStream($this->parseStream($httpResponse->body()));
    }

    /**
     * Parse a Gemini SSE body into an AiChunk generator.
     *
     * Each `data:` line is a full GenerateContentResponse. Text is concatenated
     * from all text parts; the finish reason is taken from candidates[0].finishReason.
     *
     * @param string $rawBody
     *
     * @return Generator<int, AiChunk, void, void>
     */
    private function parseStream(string $rawBody): Generator
    {
        foreach (explode("\n", $rawBody) as $line) {
            $line = trim($line);

            if (!str_starts_with($line, 'data: ')) {
                continue;
            }

            /** @var mixed $decoded */
            $decoded = json_decode(substr($line, 6), true);

            if (!is_array($decoded)) {
                continue;
            }

            $candidates = $decoded['candidates'] ?? null;

            if (!is_array($candidates) || !isset($candidates[0]) || !is_array($candidates[0])) {
                continue;
            }

            $candidate = $candidates[0];
            $contentBlock = $candidate['content'] ?? null;
            $parts = is_array($contentBlock) ? ($contentBlock['parts'] ?? null) : null;

            $text = '';

            if (is_array($parts)) {
                foreach ($parts as $part) {
                    if (is_array($part) && is_string($part['text'] ?? null)) {
                        $text .= $part['text'];
                    }
                }
            }

            $finishStr = is_string($candidate['finishReason'] ?? null) ? $candidate['finishReason'] : null;
            $finishReason = $finishStr !== null ? $this->mapFinishReason($finishStr) : null;

            if ($text !== '') {
                yield new AiChunk($text, $finishReason);
            }
        }
    }

    /**
     * Map a Gemini finishReason string to a FinishReason enum case.
     *
     * @param string $reason
     *
     * @return FinishReason
     */
    private function mapFinishReason(string $reason): FinishReason
    {
        return match ($reason) {
            'STOP' => FinishReason::STOP,
            'MAX_TOKENS' => FinishReason::LENGTH,
            'SAFETY', 'RECITATION' => FinishReason::CONTENT_FILTER,
            default => FinishReason::ERROR,
        };
    }
}
