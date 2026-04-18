<?php

declare(strict_types=1);

namespace EzPhp\Ai\Message;

/**
 * An immutable part of a multimodal message (text or image URL).
 *
 * @package EzPhp\Ai\Message
 */
final readonly class ContentPart
{
    /**
     * @param ContentPartType $type    The kind of content this part holds.
     * @param string          $content The text string or image URL.
     */
    public function __construct(
        private ContentPartType $type,
        private string $content,
    ) {
    }

    /**
     * Create a plain-text content part.
     *
     * @param string $text
     *
     * @return self
     */
    public static function text(string $text): self
    {
        return new self(ContentPartType::TEXT, $text);
    }

    /**
     * Create an image-URL content part.
     *
     * @param string $url
     *
     * @return self
     */
    public static function imageUrl(string $url): self
    {
        return new self(ContentPartType::IMAGE_URL, $url);
    }

    /**
     * @return ContentPartType
     */
    public function type(): ContentPartType
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function content(): string
    {
        return $this->content;
    }
}
