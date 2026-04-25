# ez-php/ai

Multi-provider AI client for ez-php. Supports chat completions, streaming, tool calling, and embeddings across OpenAI, Anthropic, Gemini, and Mistral.

---

## Installation

```bash
composer require ez-php/ai
```

Requires PHP 8.5 and `ez-php/http-client`.

---

## Configuration

Register `AiServiceProvider` in your application and add `config/ai.php`:

```php
// config/ai.php
return [
    'driver' => env('AI_DRIVER', 'openai'),

    'openai' => [
        'api_key'  => env('OPENAI_API_KEY', ''),
        'model'    => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    ],

    'anthropic' => [
        'api_key'     => env('ANTHROPIC_API_KEY', ''),
        'model'       => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model'   => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    ],

    'mistral' => [
        'api_key'  => env('MISTRAL_API_KEY', ''),
        'model'    => env('MISTRAL_MODEL', 'mistral-small-latest'),
        'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai'),
    ],

    'log' => [
        'inner_driver' => env('AI_LOG_INNER_DRIVER', 'openai'),
    ],
];
```

### Driver options

| `AI_DRIVER` value | Description |
|---|---|
| `openai` | OpenAI chat completions API |
| `anthropic` | Anthropic Messages API |
| `gemini` | Google Gemini generateContent API |
| `mistral` | Mistral AI (OpenAI-compatible) |
| `log` | Decorates another driver with `error_log` output |
| `null` | Returns empty responses; useful in tests |

### Environment variables

| Variable | Default | Description |
|---|---|---|
| `AI_DRIVER` | `null` | Active driver |
| `OPENAI_API_KEY` | — | OpenAI API key |
| `OPENAI_MODEL` | `gpt-4o-mini` | Default OpenAI model |
| `OPENAI_BASE_URL` | `https://api.openai.com` | Base URL (Azure / proxy support) |
| `ANTHROPIC_API_KEY` | — | Anthropic API key |
| `ANTHROPIC_MODEL` | `claude-sonnet-4-6` | Default Anthropic model |
| `ANTHROPIC_API_VERSION` | `2023-06-01` | `anthropic-version` header value |
| `GEMINI_API_KEY` | — | Google AI API key |
| `GEMINI_MODEL` | `gemini-2.0-flash` | Default Gemini model |
| `MISTRAL_API_KEY` | — | Mistral API key |
| `MISTRAL_MODEL` | `mistral-small-latest` | Default Mistral model |
| `MISTRAL_BASE_URL` | `https://api.mistral.ai` | Mistral base URL |
| `AI_LOG_INNER_DRIVER` | `openai` | Driver wrapped by the `log` driver |

---

## Basic usage

### Static facade

```php
use EzPhp\Ai\Ai;
use EzPhp\Ai\Request\AiRequest;

$response = Ai::complete(AiRequest::make('What is the capital of France?'));

echo $response->content(); // "Paris"
```

### Direct driver injection

```php
use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\Request\AiRequest;

class MyService
{
    public function __construct(private AiClientInterface $ai) {}

    public function ask(string $question): string
    {
        $response = $this->ai->complete(AiRequest::make($question));
        return $response->content();
    }
}
```

---

## Building requests

`AiRequest` is immutable. All wither methods return new instances.

```php
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Message\AiMessage;

// Single user message
$request = AiRequest::make('Hello');

// Explicit message list
$request = AiRequest::withMessages(
    AiMessage::system('You are a helpful assistant.'),
    AiMessage::user('What is 2 + 2?'),
);

// Chain withers
$request = AiRequest::make('Explain async/await')
    ->withModel('gpt-4o')
    ->withTemperature(0.7)
    ->withMaxTokens(500)
    ->withSystemPrompt('You are a concise technical writer.');

// Append a message
$request = $request->addMessage(AiMessage::user('Give an example in PHP.'));
```

---

## Messages

```php
use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Message\ContentPart;

// Plain text
AiMessage::user('Hello');
AiMessage::assistant('Hi there!');
AiMessage::system('You are a helpful assistant.');

// Multimodal (text + image URL)
AiMessage::userWithParts([
    ContentPart::text('What is in this image?'),
    ContentPart::imageUrl('https://example.com/image.png'),
]);
```

---

## Streaming

Drivers that implement `StreamingAiClientInterface` support streaming responses.

```php
use EzPhp\Ai\Ai;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\StreamingAiClientInterface;

$client = Ai::getClient();

if ($client instanceof StreamingAiClientInterface) {
    $stream = $client->stream(AiRequest::make('Tell me a story.'));

    foreach ($stream as $chunk) {
        echo $chunk->content();

        if ($chunk->isFinal()) {
            echo PHP_EOL;
            echo 'Finish reason: ' . $chunk->finishReason()?->value . PHP_EOL;
        }
    }
}

// Or collect the full text at once
$text = $stream->collect();
```

All four production drivers (OpenAI, Anthropic, Gemini, Mistral) implement `StreamingAiClientInterface`.

> **Note:** Streaming uses SSE post-hoc parsing — the full response body is buffered, then parsed line-by-line. True chunked transfer is not supported.

---

## Tool calling

Define tools, attach them to the request, and handle tool calls in a loop.

```php
use EzPhp\Ai\Ai;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Tool\ToolDefinition;

$getWeather = new ToolDefinition(
    name: 'get_weather',
    description: 'Returns the current weather for a city.',
    parameters: [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => 'The city name'],
        ],
        'required' => ['city'],
    ],
);

$request = AiRequest::make('What is the weather in Berlin?')
    ->withTools($getWeather);

$response = Ai::complete($request);

// Agentic loop
while ($response->finishReason() === FinishReason::TOOL_CALL) {
    $toolMessages = [];

    foreach ($response->toolCalls() as $call) {
        $result = match ($call->name()) {
            'get_weather' => json_encode(['temp' => '18°C', 'condition' => 'Cloudy']),
            default       => 'Unknown tool',
        };

        $toolMessages[] = AiMessage::tool($result, $call->id());
    }

    $request = $request
        ->addMessage(AiMessage::assistantWithToolCalls(...$response->toolCalls()))
        ->addMessage(...$toolMessages);  // may need multiple addMessage calls

    $response = Ai::complete($request);
}

echo $response->content();
```

> **Gemini note:** Gemini does not assign separate IDs to tool calls. The function name is used as the call ID. Use the function name as `toolCallId` in tool result messages for Gemini conversations.

> **Streaming + tool calling:** Tool calls are only parsed in `complete()`. The `stream()` path yields text chunks only.

---

## Embeddings

Use `OpenAiEmbeddingDriver` or `GeminiEmbeddingDriver` directly — embeddings are not wired through `AiServiceProvider` or the `Ai` facade.

```php
use EzPhp\Ai\Driver\OpenAiConfig;
use EzPhp\Ai\Driver\OpenAiEmbeddingDriver;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\HttpClient;

$driver = new OpenAiEmbeddingDriver(
    new HttpClient(new CurlTransport()),
    new OpenAiConfig(apiKey: $_ENV['OPENAI_API_KEY']),
);

// Returns float[]
$vector = $driver->embed('The quick brown fox');

// Override model
$vector = $driver->embed('Hello world', 'text-embedding-3-large');
```

```php
use EzPhp\Ai\Driver\GeminiConfig;
use EzPhp\Ai\Driver\GeminiEmbeddingDriver;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\HttpClient;

$driver = new GeminiEmbeddingDriver(
    new HttpClient(new CurlTransport()),
    new GeminiConfig(apiKey: $_ENV['GEMINI_API_KEY']),
);

// Default model: text-embedding-004
$vector = $driver->embed('The quick brown fox');
```

| Driver | Default model | Endpoint |
|---|---|---|
| `OpenAiEmbeddingDriver` | `text-embedding-3-small` | `POST /v1/embeddings` |
| `GeminiEmbeddingDriver` | `text-embedding-004` | `POST /v1beta/models/{model}:embedContent` |

---

## Response object

```php
$response = Ai::complete($request);

$response->content();       // string — generated text
$response->finishReason();  // FinishReason enum: STOP, LENGTH, TOOL_CALL, CONTENT_FILTER, ERROR
$response->usage();         // TokenUsage|null
$response->toolCalls();     // list<ToolCall> — non-empty when finishReason === TOOL_CALL
$response->hasToolCalls();  // bool
$response->rawBody();       // string — raw JSON from the provider

$usage = $response->usage();
if ($usage !== null) {
    $usage->inputTokens();   // int
    $usage->outputTokens();  // int
    $usage->totalTokens();   // int
}
```

---

## Logging decorator

Wrap any driver to log every request and response via `error_log`:

```php
// config/ai.php
return [
    'driver' => 'log',
    'log'    => ['inner_driver' => 'openai'],
    'openai' => ['api_key' => env('OPENAI_API_KEY')],
];
```

Or construct `LogDriver` manually with a custom logger closure:

```php
use EzPhp\Ai\Driver\LogDriver;

$driver = new LogDriver(
    $innerDriver,
    function (string $level, string $message, array $context): void {
        $this->logger->log($level, $message, $context);
    },
);
```

---

## OpenAI-compatible proxies and Azure

`OpenAiDriver` and `MistralDriver` accept a `base_url` config key, making them compatible with Azure OpenAI and any OpenAI-compatible proxy:

```php
// config/ai.php — Azure OpenAI
'openai' => [
    'api_key'  => env('AZURE_OPENAI_API_KEY'),
    'model'    => 'gpt-4o',
    'base_url' => env('AZURE_OPENAI_ENDPOINT'), // e.g. https://my-resource.openai.azure.com
],
```

---

## Testing

In unit tests, inject `NullDriver` or use `FakeTransport` from `ez-php/http-client`:

```php
use EzPhp\Ai\Driver\NullDriver;
use EzPhp\Ai\Request\AiRequest;

$driver = NullDriver::withContent('Paris');
$response = $driver->complete(AiRequest::make('What is the capital of France?'));

assertEquals('Paris', $response->content());
```

```php
use EzPhp\Ai\Driver\OpenAiDriver;
use EzPhp\Ai\Driver\OpenAiConfig;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;

$fake = new FakeTransport();
$fake->queue(200, '{"choices":[{"message":{"role":"assistant","content":"Paris"},"finish_reason":"stop"}],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}');

$driver = new OpenAiDriver(
    new HttpClient($fake),
    new OpenAiConfig('test-key'),
);

$response = $driver->complete(AiRequest::make('Capital of France?'));
assertEquals('Paris', $response->content());
```

Use `Ai::resetClient()` in `tearDown()` when tests touch the static facade to prevent state leaking between test cases.

---

## Quality suite

```bash
# Inside Docker
docker compose exec app composer full

# Individual steps
docker compose exec app composer analyse   # PHPStan level 9
docker compose exec app composer cs        # php-cs-fixer
docker compose exec app composer test      # PHPUnit
```

Start the development shell:

```bash
./start.sh
```
