<?php

declare(strict_types=1);

namespace EzPhp\Ai\Tool;

/**
 * Represents a tool/function call requested by the model in a completion response.
 *
 * @package EzPhp\Ai\Tool
 */
final readonly class ToolCall
{
    /**
     * @param string               $id        Provider-assigned identifier for this call (used to match results).
     * @param string               $name      Name of the function to invoke.
     * @param array<string, mixed> $arguments Decoded function arguments.
     */
    public function __construct(
        private string $id,
        private string $name,
        private array $arguments,
    ) {
    }

    /**
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }
}
