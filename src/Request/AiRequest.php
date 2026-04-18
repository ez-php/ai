<?php

declare(strict_types=1);

namespace EzPhp\Ai\Request;

use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Message\Role;

/**
 * An immutable value object describing a completion request to an AI provider.
 *
 * All wither methods return a new instance; the original is never mutated.
 * Use the static factory methods to construct the common cases.
 *
 * @package EzPhp\Ai\Request
 */
final readonly class AiRequest
{
    /**
     * @param list<AiMessage> $messages
     * @param string|null     $model        Provider-specific model identifier.
     * @param float|null      $temperature  Sampling temperature (0.0–2.0).
     * @param int|null        $maxTokens    Maximum tokens to generate.
     * @param string|null     $systemPrompt High-level system instructions.
     */
    private function __construct(
        private array $messages,
        private ?string $model,
        private ?float $temperature,
        private ?int $maxTokens,
        private ?string $systemPrompt,
    ) {
    }

    /**
     * Create a request from a single user text message.
     *
     * @param string $content
     *
     * @return self
     */
    public static function make(string $content): self
    {
        return new self([AiMessage::user($content)], null, null, null, null);
    }

    /**
     * Create a request from an explicit list of messages.
     *
     * @param AiMessage ...$messages
     *
     * @return self
     */
    public static function withMessages(AiMessage ...$messages): self
    {
        return new self(array_values($messages), null, null, null, null);
    }

    /**
     * Return a new instance with the given model identifier.
     *
     * @param string $model
     *
     * @return self
     */
    public function withModel(string $model): self
    {
        return new self($this->messages, $model, $this->temperature, $this->maxTokens, $this->systemPrompt);
    }

    /**
     * Return a new instance with the given sampling temperature.
     *
     * @param float $temperature Value between 0.0 and 2.0.
     *
     * @return self
     */
    public function withTemperature(float $temperature): self
    {
        return new self($this->messages, $this->model, $temperature, $this->maxTokens, $this->systemPrompt);
    }

    /**
     * Return a new instance with the given maximum token limit.
     *
     * @param int $maxTokens
     *
     * @return self
     */
    public function withMaxTokens(int $maxTokens): self
    {
        return new self($this->messages, $this->model, $this->temperature, $maxTokens, $this->systemPrompt);
    }

    /**
     * Return a new instance with the given system prompt.
     *
     * @param string $systemPrompt
     *
     * @return self
     */
    public function withSystemPrompt(string $systemPrompt): self
    {
        return new self($this->messages, $this->model, $this->temperature, $this->maxTokens, $systemPrompt);
    }

    /**
     * Return a new instance with the given message appended to the end.
     *
     * @param AiMessage $message
     *
     * @return self
     */
    public function addMessage(AiMessage $message): self
    {
        return new self(
            [...$this->messages, $message],
            $this->model,
            $this->temperature,
            $this->maxTokens,
            $this->systemPrompt,
        );
    }

    /**
     * @return list<AiMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return string|null
     */
    public function model(): ?string
    {
        return $this->model;
    }

    /**
     * @return float|null
     */
    public function temperature(): ?float
    {
        return $this->temperature;
    }

    /**
     * @return int|null
     */
    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    /**
     * @return string|null
     */
    public function systemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    /**
     * Whether the request contains a user message.
     *
     * @return bool
     */
    public function hasUserMessage(): bool
    {
        foreach ($this->messages as $message) {
            if ($message->role() === Role::USER) {
                return true;
            }
        }

        return false;
    }
}
