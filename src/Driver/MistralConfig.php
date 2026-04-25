<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

/**
 * Configuration value object for the Mistral driver.
 *
 * @package EzPhp\Ai\Driver
 */
final readonly class MistralConfig
{
    public const string DEFAULT_BASE_URL = 'https://api.mistral.ai';
    public const string DEFAULT_MODEL = 'mistral-small-latest';

    /**
     * @param string $apiKey  Mistral API key.
     * @param string $model   Default model identifier used when AiRequest has no model set.
     * @param string $baseUrl Base URL override (useful for self-hosted deployments).
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
