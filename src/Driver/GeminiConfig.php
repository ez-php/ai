<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

/**
 * Configuration value object for the Google Gemini driver.
 *
 * @package EzPhp\Ai\Driver
 */
final readonly class GeminiConfig
{
    public const string BASE_URL = 'https://generativelanguage.googleapis.com';
    public const string DEFAULT_MODEL = 'gemini-2.0-flash';

    /**
     * @param string $apiKey Google AI API key.
     * @param string $model  Default model identifier used when AiRequest has no model set.
     */
    public function __construct(
        private string $apiKey,
        private string $model = self::DEFAULT_MODEL,
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
}
