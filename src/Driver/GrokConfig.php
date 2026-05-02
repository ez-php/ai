<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

/**
 * Configuration value object for the Grok (xAI) driver.
 *
 * @package EzPhp\Ai\Driver
 */
final readonly class GrokConfig
{
    public const string DEFAULT_BASE_URL = 'https://api.x.ai';
    public const string DEFAULT_MODEL = 'grok-3-mini';

    /**
     * @param string $apiKey  xAI API key.
     * @param string $model   Default model identifier used when AiRequest has no model set.
     * @param string $baseUrl Base URL override (useful for self-hosted or proxied deployments).
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
