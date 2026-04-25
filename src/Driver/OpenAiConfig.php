<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

/**
 * Configuration value object for the OpenAI driver.
 *
 * @package EzPhp\Ai\Driver
 */
final readonly class OpenAiConfig
{
    public const string DEFAULT_BASE_URL = 'https://api.openai.com';
    public const string DEFAULT_MODEL = 'gpt-4o-mini';

    /**
     * @param string $apiKey  OpenAI API key.
     * @param string $model   Default model identifier used when AiRequest has no model set.
     * @param string $baseUrl Base URL override (useful for Azure OpenAI or API proxies).
     */
    public function __construct(
        private string $apiKey,
        private string $model = self::DEFAULT_MODEL,
        private string $baseUrl = self::DEFAULT_BASE_URL,
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
    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }
}
