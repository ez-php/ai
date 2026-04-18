<?php

declare(strict_types=1);

namespace EzPhp\Ai\Tool;

/**
 * Describes a function/tool that the model may call.
 *
 * @package EzPhp\Ai\Tool
 */
final readonly class ToolDefinition
{
    /**
     * @param string               $name        Function name (snake_case, no spaces).
     * @param string               $description Human-readable description of what the function does.
     * @param array<string, mixed> $parameters  JSON Schema object describing the function parameters.
     */
    public function __construct(
        private string $name,
        private string $description,
        private array $parameters = [],
    ) {
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }
}
