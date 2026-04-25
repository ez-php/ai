<?php

declare(strict_types=1);

namespace EzPhp\Ai\Message;

use EzPhp\Ai\Tool\ToolCall;

/**
 * An immutable conversation message with a role and content.
 *
 * Content is either a plain string or a list of ContentPart objects for
 * multimodal messages (text + images). Use the static factory methods to
 * construct the common cases.
 *
 * @package EzPhp\Ai\Message
 */
final readonly class AiMessage
{
    /**
     * @param Role                     $role       The participant role.
     * @param string|list<ContentPart> $content    Plain text or multimodal parts.
     * @param string                   $toolCallId For Role::TOOL messages: the ID of the ToolCall being answered.
     * @param list<ToolCall>           $toolCalls  For Role::ASSISTANT messages that contain tool call requests.
     */
    private function __construct(
        private Role $role,
        private string|array $content,
        private string $toolCallId = '',
        private array $toolCalls = [],
    ) {
    }

    /**
     * Create a user message with plain text content.
     *
     * @param string $content
     *
     * @return self
     */
    public static function user(string $content): self
    {
        return new self(Role::USER, $content);
    }

    /**
     * Create a user message with multimodal content parts.
     *
     * @param list<ContentPart> $parts
     *
     * @return self
     */
    public static function userWithParts(array $parts): self
    {
        return new self(Role::USER, $parts);
    }

    /**
     * Create an assistant message.
     *
     * @param string $content
     *
     * @return self
     */
    public static function assistant(string $content): self
    {
        return new self(Role::ASSISTANT, $content);
    }

    /**
     * Create an assistant message that contains tool call requests.
     *
     * Used when replaying a conversation that included a tool-calling turn.
     *
     * @param ToolCall ...$toolCalls
     *
     * @return self
     */
    public static function assistantWithToolCalls(ToolCall ...$toolCalls): self
    {
        return new self(Role::ASSISTANT, '', '', array_values($toolCalls));
    }

    /**
     * Create a system message.
     *
     * @param string $content
     *
     * @return self
     */
    public static function system(string $content): self
    {
        return new self(Role::SYSTEM, $content);
    }

    /**
     * Create a tool-result message.
     *
     * @param string $content    The result returned by the tool.
     * @param string $toolCallId The ID from the ToolCall this result answers.
     *
     * @return self
     */
    public static function tool(string $content, string $toolCallId = ''): self
    {
        return new self(Role::TOOL, $content, $toolCallId);
    }

    /**
     * Create a message with an explicit role.
     *
     * @param Role                     $role
     * @param string|list<ContentPart> $content
     *
     * @return self
     */
    public static function make(Role $role, string|array $content): self
    {
        return new self($role, $content);
    }

    /**
     * @return Role
     */
    public function role(): Role
    {
        return $this->role;
    }

    /**
     * @return string|list<ContentPart>
     */
    public function content(): string|array
    {
        return $this->content;
    }

    /**
     * The tool-call ID this message is a result for (Role::TOOL messages only).
     *
     * @return string
     */
    public function toolCallId(): string
    {
        return $this->toolCallId;
    }

    /**
     * Tool calls embedded in this assistant turn (Role::ASSISTANT messages only).
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * Whether this message carries multimodal content parts rather than plain text.
     *
     * @return bool
     */
    public function isMultimodal(): bool
    {
        return is_array($this->content);
    }

    /**
     * Return the plain-text representation of the message content.
     *
     * For multimodal messages, only text parts are concatenated (image URLs are
     * omitted). Returns an empty string if no text parts are present.
     *
     * @return string
     */
    public function textContent(): string
    {
        if (is_string($this->content)) {
            return $this->content;
        }

        $text = '';

        foreach ($this->content as $part) {
            if ($part->type() === ContentPartType::TEXT) {
                $text .= $part->content();
            }
        }

        return $text;
    }
}
