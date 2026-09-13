<?php

declare(strict_types=1);

namespace Tests\Ai\EndToEnd;

use EzPhp\Ai\Driver\OpenAiConfig;
use EzPhp\Ai\Driver\OpenAiDriver;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\HttpClient;
use RuntimeException;
use Tests\Ai\TestCase;

/**
 * Proves the whole chain — CurlTransport pump, SseDecoder, OpenAiDriver,
 * AiStream — delivers tokens while the provider is still generating.
 *
 * Runs against a `php -S` server on 127.0.0.1; no internet access needed.
 * The server lifecycle mirrors ez-php/http-client's CurlTransportStreamTest
 * rather than sharing it, because test code must not reach across packages.
 *
 * @covers \EzPhp\Ai\Driver\OpenAiDriver
 * @uses   \EzPhp\Ai\Driver\OpenAiConfig
 * @uses   \EzPhp\Ai\Driver\ProviderStream
 * @uses   \EzPhp\Ai\Request\AiRequest
 * @uses   \EzPhp\Ai\Response\AiStream
 * @uses   \EzPhp\Ai\Response\AiChunk
 * @uses   \EzPhp\Ai\Response\FinishReason
 * @uses   \EzPhp\Ai\Message\AiMessage
 * @uses   \EzPhp\Ai\Message\Role
 */
final class AiStreamEndToEndTest extends TestCase
{
    /**
     * @var resource|null
     */
    private static mixed $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException('Could not reserve a port: ' . $errstr);
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/Support/openai-stream-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '2'],
        );

        if (!is_resource($server)) {
            throw new RuntimeException('Could not start the loopback server.');
        }

        self::$server = $server;
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);

            if ($probe !== false) {
                fclose($probe);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The loopback server did not accept connections within 5 seconds.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;

        parent::tearDownAfterClass();
    }

    public function testTokensArriveWhileTheProviderIsStillGenerating(): void
    {
        $driver = new OpenAiDriver(
            new HttpClient(new CurlTransport()),
            new OpenAiConfig('test-key', 'gpt-test', 'http://127.0.0.1:' . self::$port),
        );

        $start = microtime(true);
        $stream = $driver->stream(AiRequest::make('hi'));

        $arrivals = [];
        $text = '';
        $finishReason = null;

        foreach ($stream as $chunk) {
            $arrivals[] = microtime(true) - $start;
            $text .= $chunk->content();

            if ($chunk->isFinal()) {
                $finishReason = $chunk->finishReason();
            }
        }

        $this->assertSame('Hello world', $text);
        $this->assertSame(FinishReason::STOP, $finishReason);
        $this->assertCount(4, $arrivals);
        // Two 300 ms pauses between tokens on the server.
        $this->assertGreaterThanOrEqual(0.5, $arrivals[3] - $arrivals[0]);
    }
}
