<?php

declare(strict_types=1);

namespace EzPhp\Ai\Response;

/**
 * Reason the model stopped generating tokens.
 */
enum FinishReason: string
{
    /** The model reached a natural stopping point. */
    case STOP = 'stop';

    /** The response was cut off because it hit the token limit. */
    case LENGTH = 'length';

    /** The model requested a tool/function call. */
    case TOOL_CALL = 'tool_call';

    /** Generation was halted by the provider's content safety filter. */
    case CONTENT_FILTER = 'content_filter';

    /** The request or response encountered an error. */
    case ERROR = 'error';
}
