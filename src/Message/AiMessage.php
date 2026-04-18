<?php

declare(strict_types=1);

namespace EzPhp\Ai\Message;

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
     * @param Role                    $role    The participant role.
     * @param string|list<ContentPart> $content Plain text or multimodal parts.
     */
    private function __construct(
        private Role $role,
        private string|array $content,
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
     * @param string $content
     *
     * @return self
     */
    public static function tool(string $content): self
    {
        return new self(Role::TOOL, $content);
    }

    /**
     * Create a message with an explicit role.
     *
     * @param Role                    $role
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
