<?php

declare(strict_types=1);

namespace EzPhp\Ai;

use EzPhp\Ai\Driver\AnthropicConfig;
use EzPhp\Ai\Driver\AnthropicDriver;
use EzPhp\Ai\Driver\GeminiConfig;
use EzPhp\Ai\Driver\GeminiDriver;
use EzPhp\Ai\Driver\GeminiEmbeddingDriver;
use EzPhp\Ai\Driver\GrokConfig;
use EzPhp\Ai\Driver\GrokDriver;
use EzPhp\Ai\Driver\LogDriver;
use EzPhp\Ai\Driver\MistralConfig;
use EzPhp\Ai\Driver\MistralDriver;
use EzPhp\Ai\Driver\NullDriver;
use EzPhp\Ai\Driver\NullEmbeddingDriver;
use EzPhp\Ai\Driver\OpenAiConfig;
use EzPhp\Ai\Driver\OpenAiDriver;
use EzPhp\Ai\Driver\OpenAiEmbeddingDriver;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\HttpClient;

/**
 * Binds AiClientInterface to the driver configured via config/ai.php
 * and wires the Ai static façade on boot.
 *
 * Supported drivers: openai, anthropic, gemini, mistral, grok, log, null (default)
 *
 * Minimal config/ai.php:
 *
 *   return ['driver' => 'openai', 'openai' => ['api_key' => env('OPENAI_API_KEY')]];
 *
 * Also binds EmbeddingClientInterface and wires Ai::embed()/embedBatch(), selected
 * independently via `ai.embedding_driver` (default: null — no completion driver
 * dependency). Supported embedding drivers: openai, gemini, null (default).
 *
 * @package EzPhp\Ai
 */
final class AiServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(AiClientInterface::class, function (ContainerInterface $app): AiClientInterface {
            $config = $app->make(ConfigInterface::class);
            $driver = $config->get('ai.driver', 'null');
            $driver = is_string($driver) ? $driver : 'null';

            return $this->makeDriver($driver, $config);
        });

        $this->app->bind(EmbeddingClientInterface::class, function (ContainerInterface $app): EmbeddingClientInterface {
            $config = $app->make(ConfigInterface::class);
            $driver = $config->get('ai.embedding_driver', 'null');
            $driver = is_string($driver) ? $driver : 'null';

            return $this->makeEmbeddingDriver($driver, $config);
        });
    }

    /**
     * Eagerly resolve AiClientInterface/EmbeddingClientInterface and wire them
     * to the Ai static façade.
     *
     * @return void
     */
    public function boot(): void
    {
        $client = $this->app->make(AiClientInterface::class);
        Ai::setClient($client);

        $embeddingClient = $this->app->make(EmbeddingClientInterface::class);
        Ai::setEmbeddingClient($embeddingClient);
    }

    /**
     * Construct the AiClientInterface implementation for the given driver name.
     *
     * @param string          $driver
     * @param ConfigInterface $config
     *
     * @return AiClientInterface
     */
    private function makeDriver(string $driver, ConfigInterface $config): AiClientInterface
    {
        return match ($driver) {
            'openai' => $this->makeOpenAi($config),
            'anthropic' => $this->makeAnthropic($config),
            'gemini' => $this->makeGemini($config),
            'mistral' => $this->makeMistral($config),
            'grok' => $this->makeGrok($config),
            'log' => $this->makeLog($config),
            default => NullDriver::withContent(''),
        };
    }

    /**
     * @param ConfigInterface $config
     *
     * @return OpenAiDriver
     */
    private function makeOpenAi(ConfigInterface $config): OpenAiDriver
    {
        $apiKey = $config->get('ai.openai.api_key', '');
        $model = $config->get('ai.openai.model', OpenAiConfig::DEFAULT_MODEL);
        $baseUrl = $config->get('ai.openai.base_url', OpenAiConfig::DEFAULT_BASE_URL);

        return new OpenAiDriver(
            $this->makeHttp(),
            new OpenAiConfig(
                is_string($apiKey) ? $apiKey : '',
                is_string($model) ? $model : OpenAiConfig::DEFAULT_MODEL,
                is_string($baseUrl) ? $baseUrl : OpenAiConfig::DEFAULT_BASE_URL,
            ),
            $this->streamIdleTimeout($config),
        );
    }

    /**
     * @param ConfigInterface $config
     *
     * @return AnthropicDriver
     */
    private function makeAnthropic(ConfigInterface $config): AnthropicDriver
    {
        $apiKey = $config->get('ai.anthropic.api_key', '');
        $model = $config->get('ai.anthropic.model', AnthropicConfig::DEFAULT_MODEL);
        $apiVersion = $config->get('ai.anthropic.api_version', AnthropicConfig::DEFAULT_API_VERSION);

        return new AnthropicDriver(
            $this->makeHttp(),
            new AnthropicConfig(
                is_string($apiKey) ? $apiKey : '',
                is_string($model) ? $model : AnthropicConfig::DEFAULT_MODEL,
                is_string($apiVersion) ? $apiVersion : AnthropicConfig::DEFAULT_API_VERSION,
            ),
            $this->streamIdleTimeout($config),
        );
    }

    /**
     * @param ConfigInterface $config
     *
     * @return GeminiDriver
     */
    private function makeGemini(ConfigInterface $config): GeminiDriver
    {
        $apiKey = $config->get('ai.gemini.api_key', '');
        $model = $config->get('ai.gemini.model', GeminiConfig::DEFAULT_MODEL);

        return new GeminiDriver(
            $this->makeHttp(),
            new GeminiConfig(
                is_string($apiKey) ? $apiKey : '',
                is_string($model) ? $model : GeminiConfig::DEFAULT_MODEL,
            ),
            $this->streamIdleTimeout($config),
        );
    }

    /**
     * @param ConfigInterface $config
     *
     * @return MistralDriver
     */
    private function makeMistral(ConfigInterface $config): MistralDriver
    {
        $apiKey = $config->get('ai.mistral.api_key', '');
        $model = $config->get('ai.mistral.model', MistralConfig::DEFAULT_MODEL);
        $baseUrl = $config->get('ai.mistral.base_url', MistralConfig::DEFAULT_BASE_URL);

        return new MistralDriver(
            $this->makeHttp(),
            new MistralConfig(
                is_string($apiKey) ? $apiKey : '',
                is_string($model) ? $model : MistralConfig::DEFAULT_MODEL,
                is_string($baseUrl) ? $baseUrl : MistralConfig::DEFAULT_BASE_URL,
            ),
            $this->streamIdleTimeout($config),
        );
    }

    /**
     * @param ConfigInterface $config
     *
     * @return GrokDriver
     */
    private function makeGrok(ConfigInterface $config): GrokDriver
    {
        $apiKey = $config->get('ai.grok.api_key', '');
        $model = $config->get('ai.grok.model', GrokConfig::DEFAULT_MODEL);
        $baseUrl = $config->get('ai.grok.base_url', GrokConfig::DEFAULT_BASE_URL);

        return new GrokDriver(
            $this->makeHttp(),
            new GrokConfig(
                is_string($apiKey) ? $apiKey : '',
                is_string($model) ? $model : GrokConfig::DEFAULT_MODEL,
                is_string($baseUrl) ? $baseUrl : GrokConfig::DEFAULT_BASE_URL,
            ),
            $this->streamIdleTimeout($config),
        );
    }

    /**
     * Build a LogDriver wrapping the driver named in ai.log.inner_driver.
     * Falls back to NullDriver if the inner driver name is unknown or self-referential.
     *
     * @param ConfigInterface $config
     *
     * @return LogDriver
     */
    private function makeLog(ConfigInterface $config): LogDriver
    {
        $innerName = $config->get('ai.log.inner_driver', 'null');
        $innerName = is_string($innerName) && $innerName !== 'log' ? $innerName : 'null';

        $inner = $this->makeDriver($innerName, $config);

        return new LogDriver($inner, static function (string $level, string $message, array $context): void {
            error_log(sprintf('[%s] %s %s', strtoupper($level), $message, json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}'));
        });
    }

    /**
     * Construct the EmbeddingClientInterface implementation for the given driver name.
     *
     * @param string          $driver
     * @param ConfigInterface $config
     *
     * @return EmbeddingClientInterface
     */
    private function makeEmbeddingDriver(string $driver, ConfigInterface $config): EmbeddingClientInterface
    {
        return match ($driver) {
            'openai' => $this->makeOpenAiEmbedding($config),
            'gemini' => $this->makeGeminiEmbedding($config),
            default => new NullEmbeddingDriver(),
        };
    }

    /**
     * @param ConfigInterface $config
     *
     * @return OpenAiEmbeddingDriver
     */
    private function makeOpenAiEmbedding(ConfigInterface $config): OpenAiEmbeddingDriver
    {
        $apiKey = $config->get('ai.openai.api_key', '');
        $baseUrl = $config->get('ai.openai.base_url', OpenAiConfig::DEFAULT_BASE_URL);

        // OpenAiConfig::$model is unused by OpenAiEmbeddingDriver (it uses
        // OpenAiEmbeddingDriver::DEFAULT_EMBEDDING_MODEL, or a per-call
        // override) — only apiKey()/baseUrl() are read, so the default
        // completion model is left in place here rather than passed explicitly.
        return new OpenAiEmbeddingDriver(
            $this->makeHttp(),
            new OpenAiConfig(
                is_string($apiKey) ? $apiKey : '',
                baseUrl: is_string($baseUrl) ? $baseUrl : OpenAiConfig::DEFAULT_BASE_URL,
            ),
        );
    }

    /**
     * @param ConfigInterface $config
     *
     * @return GeminiEmbeddingDriver
     */
    private function makeGeminiEmbedding(ConfigInterface $config): GeminiEmbeddingDriver
    {
        $apiKey = $config->get('ai.gemini.api_key', '');

        // Same reasoning as makeOpenAiEmbedding(): GeminiConfig::$model is
        // unused by GeminiEmbeddingDriver.
        return new GeminiEmbeddingDriver(
            $this->makeHttp(),
            new GeminiConfig(is_string($apiKey) ? $apiKey : ''),
        );
    }

    /**
     * Read ai.stream_idle_timeout; non-positive or non-numeric values fall back to the default.
     *
     * @param ConfigInterface $config
     *
     * @return int
     */
    private function streamIdleTimeout(ConfigInterface $config): int
    {
        $value = $config->get('ai.stream_idle_timeout', StreamingAiClientInterface::DEFAULT_STREAM_IDLE_TIMEOUT_SECONDS);

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : StreamingAiClientInterface::DEFAULT_STREAM_IDLE_TIMEOUT_SECONDS;
    }

    /**
     * @return HttpClient
     */
    private function makeHttp(): HttpClient
    {
        return new HttpClient(new CurlTransport());
    }
}
