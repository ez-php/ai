# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

### 3 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "0.*"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` |
|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 |
| `ez-php/framework` | 3307 | — |
| `ez-php/orm` | 3309 | — |
| `ez-php/cache` | — | 6380 |
| **next free** | **3310** | **6381** |

Only set a port for services the module actually uses. Modules without external services need no port config.

### 4 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/ai

## Source structure

```
src/
├── AiClientInterface.php            — contract: complete(AiRequest): AiResponse
├── StreamingAiClientInterface.php   — extends AiClientInterface; adds stream(): AiStream
├── AiEmbeddingConfig.php            — config VO for embedding requests: model + optional dimensions (truncation)
├── EmbeddingClientInterface.php     — contract: embed(string, AiEmbeddingConfig): float[]
├── AiException.php                  — base exception for the module
├── AiRequestException.php           — thrown on HTTP error or malformed provider response
├── Ai.php                           — static facade backed by AiClientInterface singleton
├── AiServiceProvider.php            — binds AiClientInterface from config; wires Ai facade

├── Driver/
│   ├── OpenAiConfig.php             — config VO: apiKey, model, baseUrl (supports proxies)
│   ├── OpenAiDriver.php             — OpenAI chat completions; streaming; tool calling
│   ├── AnthropicConfig.php          — config VO: apiKey, model, apiVersion
│   ├── AnthropicDriver.php          — Anthropic Messages API; streaming; tool calling
│   ├── GeminiConfig.php             — config VO: apiKey, model
│   ├── GeminiDriver.php             — Gemini generateContent; streaming via streamGenerateContent
│   ├── MistralConfig.php            — config VO: apiKey, model, baseUrl
│   ├── MistralDriver.php            — delegates to OpenAiDriver (Mistral is OpenAI-compatible)
│   ├── GrokConfig.php               — config VO: apiKey, model, baseUrl (default: https://api.x.ai, grok-3-mini)
│   ├── GrokDriver.php               — delegates to OpenAiDriver (Grok is OpenAI-compatible)
│   ├── LogDriver.php                — decorator: logs every request/response to a PSR logger
│   ├── NullDriver.php               — returns a fixed response; useful for tests and stubs
│   ├── OpenAiEmbeddingDriver.php    — OpenAI /v1/embeddings; returns float[]
│   └── GeminiEmbeddingDriver.php    — Gemini embedContent; returns float[]

├── Message/
│   ├── Role.php                     — enum: USER, ASSISTANT, SYSTEM, TOOL
│   ├── ContentPartType.php          — enum: TEXT, IMAGE_URL
│   ├── ContentPart.php              — multimodal part: text or image URL
│   └── AiMessage.php                — immutable message VO; plain text or multimodal parts; tool call support

├── Request/
│   └── AiRequest.php                — immutable request VO; clone-based withers; withTools()

├── Response/
│   ├── FinishReason.php             — enum: STOP, LENGTH, TOOL_CALL, CONTENT_FILTER, ERROR
│   ├── TokenUsage.php               — inputTokens + outputTokens
│   ├── AiResponse.php               — immutable response VO; content, finishReason, usage, toolCalls
│   ├── AiChunk.php                  — single streaming chunk: content + optional finishReason
│   └── AiStream.php                 — IteratorAggregate<int, AiChunk> backed by a Generator; collect()

└── Tool/
    ├── ToolDefinition.php           — describes a callable tool: name, description, parameters (JSON Schema)
    └── ToolCall.php                 — a tool call requested by the model: id, name, arguments

tests/
├── TestCase.php
├── AiTest.php                       — facade lazy init, setClient/resetClient, delegation
├── AiServiceProviderTest.php        — all driver selections, log driver variants, facade wiring
├── AiExceptionTest.php              — base exception hierarchy
├── AiRequestExceptionTest.php       — fromResponse factory, message and context accessors
├── Message/
│   ├── RoleTest.php
│   ├── ContentPartTypeTest.php
│   ├── ContentPartTest.php
│   └── AiMessageTest.php            — all factory methods, toolCallId, toolCalls, textContent
├── Request/
│   └── AiRequestTest.php            — make/withMessages, all withers including withTools, hasTools
├── Response/
│   ├── FinishReasonTest.php
│   ├── TokenUsageTest.php
│   ├── AiResponseTest.php           — getters, isComplete, hasToolCalls
│   ├── AiChunkTest.php              — content, finishReason, isFinal
│   └── AiStreamTest.php             — iteration order, collect, one-shot semantics
├── Tool/
│   ├── ToolDefinitionTest.php
│   └── ToolCallTest.php
├── Driver/
│   ├── NullDriverTest.php
│   ├── LogDriverTest.php
│   ├── OpenAiDriverTest.php         — URL, headers, model, finish reasons, error handling
│   ├── OpenAiStreamTest.php         — SSE parsing, chunk order, stream: true, error handling
│   ├── OpenAiToolTest.php           — tool serialization, tool call parsing, tool result messages
│   ├── OpenAiEmbeddingDriverTest.php
│   ├── AnthropicDriverTest.php
│   ├── AnthropicStreamTest.php
│   ├── AnthropicToolTest.php
│   ├── GeminiDriverTest.php
│   ├── GeminiStreamTest.php
│   ├── GeminiToolTest.php
│   ├── GeminiEmbeddingDriverTest.php
│   ├── MistralDriverTest.php
│   ├── MistralStreamTest.php
│   └── GrokDriverTest.php
└── Support/
    ├── FakeConfig.php               — ConfigInterface backed by array (for AiServiceProvider tests)
    └── FakeContainer.php            — ContainerInterface with bind/make and wasBound helper
```

---

## Key classes and responsibilities

### AiClientInterface / StreamingAiClientInterface

`AiClientInterface` is the primary contract: one method `complete(AiRequest): AiResponse`. Drivers that also support streaming implement `StreamingAiClientInterface`, which extends `AiClientInterface` and adds `stream(AiRequest): AiStream`. All five production drivers (OpenAI, Anthropic, Gemini, Mistral, Grok) implement `StreamingAiClientInterface`.

---

### AiRequest (`src/Request/AiRequest.php`)

Immutable value object. Private constructor + static factories (`make`, `withMessages`). All state changes return new instances (clone-based withers). Carries: messages, model, temperature, maxTokens, systemPrompt, and tools. `withTools(ToolDefinition ...$tools)` replaces the tools list; `hasTools()` is used by drivers to conditionally include the tools key in the request body.

---

### AiResponse (`src/Response/AiResponse.php`)

Immutable value object produced exclusively by drivers. Carries: content string, finishReason, TokenUsage, rawBody, and a `list<ToolCall>` for tool-calling responses. `hasToolCalls()` is a convenience predicate. When `finishReason === TOOL_CALL`, `toolCalls()` contains the calls requested by the model.

---

### AiStream / AiChunk (`src/Response/`)

`AiStream` wraps a `Generator<int, AiChunk, void, void>` and implements `IteratorAggregate`. It is one-shot — a consumed generator cannot be rewound. `collect()` uses a `while ($generator->valid())` loop (not `foreach`) to avoid calling `rewind()` on an already-started generator. `AiChunk` carries a content string and an optional `FinishReason`; `isFinal()` is true when finishReason is set.

---

### OpenAiDriver (`src/Driver/OpenAiDriver.php`)

Handles the OpenAI chat completions API. `buildBody()` serializes tools as `{"type":"function","function":{...}}` when `$request->hasTools()`. `serializeMessage()` handles Role::TOOL (adds `tool_call_id`) and Role::ASSISTANT with tool calls (serializes `tool_calls` array with JSON-encoded arguments). `parseResponse()` allows null content when `tool_calls` are present. `parseToolCalls()` JSON-decodes the `function.arguments` string.

---

### AnthropicDriver (`src/Driver/AnthropicDriver.php`)

Handles the Anthropic Messages API. Tools are serialized with `input_schema` (not `parameters`). Role::TOOL messages are converted to user-role messages with a `tool_result` content block. Role::ASSISTANT messages with tool calls become `tool_use` content blocks. `parseResponse()` uses separate `extractTextContent()` (returns `''` when missing) and `parseToolCalls()` methods; throws only if both content and tool calls are empty.

---

### GeminiDriver (`src/Driver/GeminiDriver.php`)

Handles the Gemini generateContent and streamGenerateContent APIs. Tools become `tools: [{"function_declarations": [...]}]`. Role::TOOL becomes a `functionResponse` part. Role::ASSISTANT with tool calls becomes `functionCall` parts. For Gemini, the function name serves as the call ID (Gemini has no separate call ID). `parseResponse()` detects function calls first; if found, sets `FinishReason::TOOL_CALL` regardless of the `finishReason` field.

---

### MistralDriver (`src/Driver/MistralDriver.php`)

Pure delegation to `OpenAiDriver` via composition. Mistral's API is OpenAI-compatible; `MistralConfig` maps to `OpenAiConfig` with the Mistral base URL. Both `complete()` and `stream()` delegate to `$this->inner`.

---

### GrokDriver (`src/Driver/GrokDriver.php`)

Pure delegation to `OpenAiDriver` via composition. Grok's API is OpenAI-compatible; `GrokConfig` maps to `OpenAiConfig` with the xAI base URL (`https://api.x.ai`) and default model `grok-3-mini`. Both `complete()` and `stream()` delegate to `$this->inner`.

---

### Ai (`src/Ai.php`)

Static facade holding `private static ?AiClientInterface $client`. `getClient()` returns the singleton, lazily initialising a `NullDriver` when none is set. `AiServiceProvider::boot()` calls `Ai::setClient()`. `Ai::resetClient()` is called in test tearDown to prevent static state leaking.

---

### AiServiceProvider (`src/AiServiceProvider.php`)

`register()` binds `AiClientInterface` with a factory closure that reads the `ai.driver` config key and delegates to private factory methods (`makeOpenAi()`, `makeAnthropic()`, `makeGemini()`, `makeMistral()`, `makeGrok()`, `makeLog()`, `makeNull()`). `makeLog()` guards against self-referential configuration. `boot()` eagerly resolves the binding and wires the `Ai` facade.

---

## Design decisions and constraints

- **All HTTP I/O via `ez-php/http-client`.** Drivers never instantiate transports directly — they receive `HttpClient` by injection. Tests use `FakeTransport` to buffer requests and return pre-built responses without network I/O.
- **SSE post-hoc parsing (not real streaming).** `ez-php/http-client` buffers the full response body. Drivers send `stream: true` (or use `?alt=sse`), receive the full SSE body, then parse it line-by-line via a `Generator`. This is simpler than true streaming and sufficient for the use-cases targeted.
- **`AiStream::collect()` uses a while loop.** PHP generators throw when `rewind()` is called after the first yield. `foreach ($this as ...)` would call `rewind()` via `getIterator()` on the second call. The while-loop pattern calls `valid()`/`current()`/`next()` directly, so a second `collect()` call on an exhausted stream returns `''` instead of throwing.
- **Gemini uses function name as call ID.** Gemini's API does not assign separate call IDs to function calls. `GeminiDriver::parseToolCalls()` sets `id = name`. Callers must use `toolCallId = functionName` in tool result messages for Gemini conversations.
- **Mistral and Grok delegate to OpenAiDriver.** Both Mistral's and Grok's APIs are OpenAI-compatible. `MistralDriver` and `GrokDriver` are thin wrappers that construct an `OpenAiDriver` with their respective config-derived `OpenAiConfig`. No logic is duplicated.
- **No streaming tool support.** `stream()` does not parse or yield tool calls. Streaming and tool calling are intentionally separate concerns — the streaming path yields text chunks only. To use tool calling, use `complete()`.
- **`AiRequest` and `AiResponse` are immutable.** All state transitions return new instances. This makes requests safe to cache, share across workers, and pass to multiple drivers without mutation risk.
- **`AiServiceProvider` depends on `ez-php/contracts`.** The service provider is the only file with a framework dependency. All driver and value-object code is framework-agnostic.

---

## Testing approach

No external infrastructure required. All tests use `FakeTransport` from `ez-php/http-client` to intercept HTTP calls and return synthetic responses. No real API keys, no network calls, no Docker services beyond the base PHP container.

- Driver tests verify URL construction, header serialization, request body structure, response parsing, finish reason mapping, and error handling — all via `FakeTransport`.
- Streaming tests parse synthetic SSE bodies (pre-built strings) into `AiStream` via the same driver code, verifying chunk order, `collect()`, and one-shot semantics.
- Tool tests verify tool definition serialization, tool call response parsing, and tool result message round-trips for all three major providers.
- `AiServiceProviderTest` uses `FakeContainer` and `FakeConfig` (in `tests/Support/`) to test service provider wiring without the full framework container.
- `Ai::resetClient()` is called in `tearDown()` of `AiTest` and `AiServiceProviderTest` to clear static state between test classes.

---

## What does not belong in this module

| Concern | Where it belongs |
|---------|-----------------|
| Real HTTP streaming (chunked transfer) | `ez-php/http-client` (would need transport-level streaming support) |
| Prompt templates / prompt management | Application layer |
| Conversation/session persistence | Application layer (store `AiMessage` lists in a database) |
| Rate limiting / retry with backoff | Application layer or a decorator over `AiClientInterface` |
| Cost tracking / token budgeting | Application layer |
| Fine-tuning API calls | A separate driver or application layer |
| Image generation (DALL-E, Imagen) | A separate interface/driver (not chat completions) |
| Audio transcription / text-to-speech | A separate interface/driver |
| Vector database integration | Application layer (use `EmbeddingClientInterface` + your own store) |
| RAG pipelines | Application layer |
| Agent orchestration loops | Application layer |
