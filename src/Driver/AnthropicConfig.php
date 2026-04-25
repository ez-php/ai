<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

/**
 * Configuration value object for the Anthropic driver.
 *
 * @package EzPhp\Ai\Driver
 */
final readonly class AnthropicConfig
{
    public const string DEFAULT_MODEL = 'claude-sonnet-4-6';
    public const string DEFAULT_API_VERSION = '2023-06-01';
    public const string BASE_URL = 'https://api.anthropic.com';

    /**
     * @param string $apiKey     Anthropic API key.
     * @param string $model      Default model identifier used when AiRequest has no model set.
     * @param string $apiVersion Value for the required `anthropic-version` header.
     */
    public function __construct(
        private string $apiKey,
        private string $model = self::DEFAULT_MODEL,
        private string $apiVersion = self::DEFAULT_API_VERSION,
    ) {
    }

    /**
     * @return string
     */
    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @return string
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * @return string
     */
    public function apiVersion(): string
    {
        return $this->apiVersion;
    }
}
