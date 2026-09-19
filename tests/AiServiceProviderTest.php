<?php

declare(strict_types=1);

namespace Tests\Ai;

use EzPhp\Ai\Ai;
use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiServiceProvider;
use EzPhp\Ai\Driver\AnthropicDriver;
use EzPhp\Ai\Driver\GeminiDriver;
use EzPhp\Ai\Driver\GeminiEmbeddingDriver;
use EzPhp\Ai\Driver\GrokDriver;
use EzPhp\Ai\Driver\LogDriver;
use EzPhp\Ai\Driver\MistralDriver;
use EzPhp\Ai\Driver\NullDriver;
use EzPhp\Ai\Driver\NullEmbeddingDriver;
use EzPhp\Ai\Driver\OpenAiDriver;
use EzPhp\Ai\Driver\OpenAiEmbeddingDriver;
use EzPhp\Ai\EmbeddingClientInterface;
use Tests\Ai\Support\FakeConfig;
use Tests\Ai\Support\FakeContainer;

/**
 * @covers \EzPhp\Ai\AiServiceProvider
 * @uses   \EzPhp\Ai\Ai
 * @uses   \EzPhp\Ai\Driver\NullDriver
 * @uses   \EzPhp\Ai\Driver\OpenAiDriver
 * @uses   \EzPhp\Ai\Driver\OpenAiConfig
 * @uses   \EzPhp\Ai\Driver\AnthropicDriver
 * @uses   \EzPhp\Ai\Driver\AnthropicConfig
 * @uses   \EzPhp\Ai\Driver\GeminiDriver
 * @uses   \EzPhp\Ai\Driver\GeminiConfig
 * @uses   \EzPhp\Ai\Driver\GrokDriver
 * @uses   \EzPhp\Ai\Driver\GrokConfig
 * @uses   \EzPhp\Ai\Driver\MistralDriver
 * @uses   \EzPhp\Ai\Driver\MistralConfig
 * @uses   \EzPhp\Ai\Driver\LogDriver
 * @uses   \EzPhp\Ai\Response\AiResponse
 * @uses   \EzPhp\Ai\Response\TokenUsage
 * @uses   \EzPhp\Ai\Response\FinishReason
 * @uses   \EzPhp\Ai\AiException
 * @uses   \EzPhp\Ai\AiRequestException
 * @uses   \Tests\Support\FakeConfig
 * @uses   \Tests\Support\FakeContainer
 */
final class AiServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        Ai::resetClient();
        Ai::resetEmbeddingClient();
    }

    protected function tearDown(): void
    {
        Ai::resetClient();
        Ai::resetEmbeddingClient();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $configData
     *
     * @return AiServiceProvider
     */
    private function makeProvider(array $configData = []): AiServiceProvider
    {
        return new AiServiceProvider(new FakeContainer(new FakeConfig($configData)));
    }

    /**
     * Register and boot the provider, returning the resolved AiClientInterface.
     *
     * @param array<string, mixed> $configData
     *
     * @return AiClientInterface
     */
    private function resolveDriver(array $configData): AiClientInterface
    {
        $provider = $this->makeProvider($configData);
        $provider->register();
        $provider->boot();

        return Ai::getClient();
    }

    // ─── Driver selection ─────────────────────────────────────────────────────

    public function testNullDriverIsDefaultWhenNoDriverConfigured(): void
    {
        $this->assertInstanceOf(NullDriver::class, $this->resolveDriver([]));
    }

    public function testNullDriverSelectedExplicitly(): void
    {
        $this->assertInstanceOf(NullDriver::class, $this->resolveDriver(['ai.driver' => 'null']));
    }

    public function testUnknownDriverFallsBackToNull(): void
    {
        $this->assertInstanceOf(NullDriver::class, $this->resolveDriver(['ai.driver' => 'something-unknown']));
    }

    public function testOpenAiDriverSelected(): void
    {
        $client = $this->resolveDriver([
            'ai.driver' => 'openai',
            'ai.openai.api_key' => 'sk-test',
        ]);

        $this->assertInstanceOf(OpenAiDriver::class, $client);
    }

    public function testAnthropicDriverSelected(): void
    {
        $client = $this->resolveDriver([
            'ai.driver' => 'anthropic',
            'ai.anthropic.api_key' => 'sk-ant-test',
        ]);

        $this->assertInstanceOf(AnthropicDriver::class, $client);
    }

    public function testGeminiDriverSelected(): void
    {
        $client = $this->resolveDriver([
            'ai.driver' => 'gemini',
            'ai.gemini.api_key' => 'gemini-test',
        ]);

        $this->assertInstanceOf(GeminiDriver::class, $client);
    }

    public function testGrokDriverSelected(): void
    {
        $client = $this->resolveDriver([
            'ai.driver' => 'grok',
            'ai.grok.api_key' => 'xai-test',
        ]);

        $this->assertInstanceOf(GrokDriver::class, $client);
    }

    public function testMistralDriverSelected(): void
    {
        $client = $this->resolveDriver([
            'ai.driver' => 'mistral',
            'ai.mistral.api_key' => 'mistral-test',
        ]);

        $this->assertInstanceOf(MistralDriver::class, $client);
    }

    public function testLogDriverSelected(): void
    {
        $client = $this->resolveDriver([
            'ai.driver' => 'log',
            'ai.log.inner_driver' => 'null',
        ]);

        $this->assertInstanceOf(LogDriver::class, $client);
    }

    public function testLogDriverWithOpenAiInner(): void
    {
        $client = $this->resolveDriver([
            'ai.driver' => 'log',
            'ai.log.inner_driver' => 'openai',
            'ai.openai.api_key' => 'sk-test',
        ]);

        $this->assertInstanceOf(LogDriver::class, $client);
    }

    public function testLogDriverSelfReferentialInnerFallsBackToNull(): void
    {
        // Prevents infinite recursion: log wrapping log
        $this->assertInstanceOf(LogDriver::class, $this->resolveDriver([
            'ai.driver' => 'log',
            'ai.log.inner_driver' => 'log',
        ]));
    }

    // ─── Facade wiring ────────────────────────────────────────────────────────

    public function testBootWiresAiFacade(): void
    {
        $provider = $this->makeProvider(['ai.driver' => 'null']);
        $provider->register();

        $before = Ai::getClient();

        $provider->boot();
        $after = Ai::getClient();

        $this->assertNotSame($before, $after);
        $this->assertInstanceOf(NullDriver::class, $after);
    }

    public function testLogDriverLoggerClosureIsExecutedOnComplete(): void
    {
        $this->resolveDriver([
            'ai.driver' => 'log',
            'ai.log.inner_driver' => 'null',
        ]);

        // Redirect error_log output so the closure runs without producing test output
        $original = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');

        try {
            $response = Ai::complete(\EzPhp\Ai\Request\AiRequest::make('ping'));
        } finally {
            ini_set('error_log', $original);
        }

        $this->assertSame('', $response->content());
    }

    public function testRegisterBindsAiClientInterface(): void
    {
        $container = new FakeContainer(new FakeConfig([]));
        $provider = new AiServiceProvider($container);
        $provider->register();

        $this->assertTrue($container->wasBound(AiClientInterface::class));
    }

    // ─── Stream idle timeout ──────────────────────────────────────────────────

    public function testStreamIdleTimeoutReachesTheDriver(): void
    {
        $client = $this->resolveDriver(['ai.driver' => 'anthropic', 'ai.stream_idle_timeout' => 45]);

        $this->assertSame(45, $this->streamIdleTimeoutOf($client, AnthropicDriver::class));
    }

    public function testStreamIdleTimeoutDefaultsTo120(): void
    {
        $client = $this->resolveDriver(['ai.driver' => 'openai']);

        $this->assertSame(120, $this->streamIdleTimeoutOf($client, OpenAiDriver::class));
    }

    public function testNumericStringStreamIdleTimeoutIsAccepted(): void
    {
        $client = $this->resolveDriver(['ai.driver' => 'gemini', 'ai.stream_idle_timeout' => '60']);

        $this->assertSame(60, $this->streamIdleTimeoutOf($client, GeminiDriver::class));
    }

    public function testInvalidStreamIdleTimeoutFallsBackToDefault(): void
    {
        $client = $this->resolveDriver(['ai.driver' => 'openai', 'ai.stream_idle_timeout' => 'abc']);

        $this->assertSame(120, $this->streamIdleTimeoutOf($client, OpenAiDriver::class));
    }

    public function testStreamIdleTimeoutReachesDelegatingDrivers(): void
    {
        foreach (['grok' => GrokDriver::class, 'mistral' => MistralDriver::class] as $name => $class) {
            $client = $this->resolveDriver(['ai.driver' => $name, 'ai.stream_idle_timeout' => 33]);
            $this->assertInstanceOf($class, $client);

            $inner = (new \ReflectionProperty($class, 'inner'))->getValue($client);
            $this->assertInstanceOf(OpenAiDriver::class, $inner);
            $this->assertSame(33, $this->streamIdleTimeoutOf($inner, OpenAiDriver::class));
        }
    }

    /**
     * Read the private streamIdleTimeout property — the drivers expose no getter
     * because nothing but this wiring test needs it.
     *
     * @param object       $client
     * @param class-string $class
     *
     * @return mixed
     */
    private function streamIdleTimeoutOf(object $client, string $class): mixed
    {
        $this->assertInstanceOf($class, $client);

        return (new \ReflectionProperty($class, 'streamIdleTimeout'))->getValue($client);
    }

    // ─── Embedding driver selection ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $configData
     *
     * @return EmbeddingClientInterface
     */
    private function resolveEmbeddingDriver(array $configData): EmbeddingClientInterface
    {
        $provider = $this->makeProvider($configData);
        $provider->register();
        $provider->boot();

        return Ai::getEmbeddingClient();
    }

    public function testNullEmbeddingDriverIsDefaultWhenNoneConfigured(): void
    {
        $this->assertInstanceOf(NullEmbeddingDriver::class, $this->resolveEmbeddingDriver([]));
    }

    public function testUnknownEmbeddingDriverFallsBackToNull(): void
    {
        $this->assertInstanceOf(
            NullEmbeddingDriver::class,
            $this->resolveEmbeddingDriver(['ai.embedding_driver' => 'something-unknown']),
        );
    }

    public function testOpenAiEmbeddingDriverSelected(): void
    {
        $this->assertInstanceOf(
            OpenAiEmbeddingDriver::class,
            $this->resolveEmbeddingDriver(['ai.embedding_driver' => 'openai']),
        );
    }

    public function testGeminiEmbeddingDriverSelected(): void
    {
        $this->assertInstanceOf(
            GeminiEmbeddingDriver::class,
            $this->resolveEmbeddingDriver(['ai.embedding_driver' => 'gemini']),
        );
    }

    public function testEmbeddingDriverIsIndependentFromCompletionDriver(): void
    {
        $provider = $this->makeProvider(['ai.driver' => 'openai', 'ai.embedding_driver' => 'gemini']);
        $provider->register();
        $provider->boot();

        $this->assertInstanceOf(OpenAiDriver::class, Ai::getClient());
        $this->assertInstanceOf(GeminiEmbeddingDriver::class, Ai::getEmbeddingClient());
    }
}
