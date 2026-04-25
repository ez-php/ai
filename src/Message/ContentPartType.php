<?php

declare(strict_types=1);

namespace EzPhp\Ai\Message;

/**
 * Type discriminator for a multimodal content part.
 */
enum ContentPartType: string
{
    /** Plain text content. */
    case TEXT = 'text';

    /** A URL pointing to an image resource. */
    case IMAGE_URL = 'image_url';
}
