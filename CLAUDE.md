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
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
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
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

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

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum`,
`opcache` → `OPCache`, and `dotenv` → `Env` are existing exceptions the guess
gets wrong).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks every
  `CLAUDE.md` copy as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. `ez-php/mail`'s Mailpit service is the one other module with published host ports: SMTP `1025` and web UI `8025`, mapped through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above), documented in `modules/mail/.env.example`. It isn't a table column because no other module runs Mailpit, so there is nothing to collide with — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/ai

## Source structure

```
src/
├── AiClientInterface.php            — contract: complete(AiRequest): AiResponse
├── StreamingAiClientInterface.php   — extends AiClientInterface; adds stream(): AiStream
├── AiEmbeddingConfig.php            — config VO for embedding requests: model + optional dimensions (truncation); currently unused by any driver — see Design Decisions
├── EmbeddingClientInterface.php     — contract: embed(string, ?string $model): float[], embedBatch(list<string>, ?string $model): list<float[]>
├── AiException.php                  — base exception for the module
├── AiRequestException.php           — thrown on HTTP error or malformed provider response
├── AiStreamException.php            — mid-stream failure: transport, provider error event, truncation
├── Ai.php                           — static facade backed by AiClientInterface + EmbeddingClientInterface singletons
├── AiServiceProvider.php            — binds AiClientInterface + EmbeddingClientInterface from config; wires Ai facade

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
│   ├── NullEmbeddingDriver.php      — returns empty vectors; default when ai.embedding_driver is unset
│   ├── ProviderStream.php           — @internal: SseDecoder + HttpStreamException → AiStreamException
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
│   └── AiStream.php                 — IteratorAggregate<int, AiChunk> backed by a Generator; collect(), toSseEvents()

└── Tool/
    ├── ToolDefinition.php           — describes a callable tool: name, description, parameters (JSON Schema)
    └── ToolCall.php                 — a tool call requested by the model: id, name, arguments

tests/
├── TestCase.php
├── AiTest.php                       — facade lazy init, setClient/resetClient, delegation
├── AiServiceProviderTest.php        — all driver selections, log driver variants, facade wiring, embedding driver selection (openai/gemini/null) independent of the completion driver
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
├── EndToEnd/
│   └── AiStreamEndToEndTest.php     — OpenAiDriver on CurlTransport against php -S: tokens arrive before generation ends
└── Support/
    ├── openai-stream-server.php     — router for that server
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

Static facade holding `private static ?AiClientInterface $client` and, independently, `private static ?EmbeddingClientInterface $embeddingClient`. `getClient()`/`getEmbeddingClient()` return their respective singleton, lazily initialising `NullDriver`/`NullEmbeddingDriver` when none is set. `AiServiceProvider::boot()` calls `Ai::setClient()` and `Ai::setEmbeddingClient()`. `Ai::resetClient()`/`resetEmbeddingClient()` are called in test tearDown to prevent static state leaking — they are separate methods (not folded into one `reset()`) because the two clients are independent singletons wired from independent config keys.

`Ai::embed(string $input, ?string $model = null): float[]` and `Ai::embedBatch(list<string> $inputs, ?string $model = null): list<float[]>` delegate to `getEmbeddingClient()`, mirroring `complete()`'s delegation to `getClient()`.

---

### AiServiceProvider (`src/AiServiceProvider.php`)

`register()` binds `AiClientInterface` with a factory closure that reads the `ai.driver` config key and delegates to private factory methods (`makeOpenAi()`, `makeAnthropic()`, `makeGemini()`, `makeMistral()`, `makeGrok()`, `makeLog()`, `makeNull()`). `makeLog()` guards against self-referential configuration. It also binds `EmbeddingClientInterface` with a separate factory closure reading `ai.embedding_driver` (`makeOpenAiEmbedding()`/`makeGeminiEmbedding()`/`NullEmbeddingDriver` default) — an independent config key, so an application can run `ai.driver=anthropic` for chat and `ai.embedding_driver=openai` for embeddings (Anthropic has no embeddings API) without conflict. `boot()` eagerly resolves both bindings and wires the `Ai` facade.

`makeOpenAiEmbedding()`/`makeGeminiEmbedding()` reuse the completion drivers' `api_key`/`base_url` config keys (`ai.openai.api_key`, `ai.gemini.api_key`, …) rather than introducing separate embedding-specific credential keys — same provider, same credentials, one fewer config surface to keep in sync. They deliberately don't pass `OpenAiConfig`/`GeminiConfig`'s `model` parameter, since `OpenAiEmbeddingDriver`/`GeminiEmbeddingDriver` never read it (see Design Decisions).

`AiServiceProvider::makeLog()` writes `LogDriver` output with a plain `error_log()` closure, not `ez-php/logging` — this module must stay usable without it. The context is encoded with `JSON_PARTIAL_OUTPUT_ON_ERROR` (falling back to `{}`) so an unencodable value never produces an empty log line.

---

## Design decisions and constraints

- **All HTTP I/O via `ez-php/http-client`.** Drivers never instantiate transports directly — they receive `HttpClient` by injection. Tests use `FakeTransport` to buffer requests and return pre-built responses without network I/O.
- **Incremental streaming over `HttpStream`.** Drivers call `HttpRequest::stream()` and parse `SseDecoder` messages as they arrive (`ProviderStream::messages()` also converts `HttpStreamException` into `AiStreamException`). `stream()` stays eager: it returns after the provider's headers, so HTTP errors are thrown before a controller builds a `StreamedResponse`.
- **Completion is explicit per provider.** OpenAI (and Grok, Mistral) end with `data: [DONE]`, Anthropic with `message_stop`, Gemini with a candidate carrying `finishReason`. A stream that ends without it throws `AiStreamException::truncated()` — before incremental transport, such a cut looked like a complete answer. Error events (`{"error": …}`, Anthropic `event: error`) throw `AiStreamException::fromProviderError()`. Unparseable JSON is still skipped.
- **Gemini finish-only candidates yield a chunk.** Gemini often sends the finish reason in an event without text; the parser yields `AiChunk('', $finishReason)` for it (previously dropped).
- **`AI_STREAM_IDLE_TIMEOUT` (default 120 s).** Longer than http-client's 30 s because reasoning models may send nothing before the first token. Passed as the optional third constructor argument; Grok and Mistral forward it to their inner `OpenAiDriver`.
- **`AiStream::toSseEvents()`** maps chunks to `EzPhp\Http\Sse\SseEvent` for `StreamedResponse::sse()` (`token` events with JSON `{"content"}`, a final `done` event with `{"finish_reason"}`), so this module requires `ez-php/http` explicitly. It uses the same `valid()`/`next()` loop as `collect()`.
- **`AiStream::collect()` uses a while loop.** PHP generators throw when `rewind()` is called after the first yield. `foreach ($this as ...)` would call `rewind()` via `getIterator()` on the second call. The while-loop pattern calls `valid()`/`current()`/`next()` directly, so a second `collect()` call on an exhausted stream returns `''` instead of throwing.
- **Gemini uses function name as call ID.** Gemini's API does not assign separate call IDs to function calls. `GeminiDriver::parseToolCalls()` sets `id = name`. Callers must use `toolCallId = functionName` in tool result messages for Gemini conversations.
- **Mistral and Grok delegate to OpenAiDriver.** Both Mistral's and Grok's APIs are OpenAI-compatible. `MistralDriver` and `GrokDriver` are thin wrappers that construct an `OpenAiDriver` with their respective config-derived `OpenAiConfig`. No logic is duplicated.
- **No streaming tool support.** `stream()` does not parse or yield tool calls. Streaming and tool calling are intentionally separate concerns — the streaming path yields text chunks only. To use tool calling, use `complete()`.
- **`AiRequest` and `AiResponse` are immutable.** All state transitions return new instances. This makes requests safe to cache, share across workers, and pass to multiple drivers without mutation risk.
- **`AiServiceProvider` depends on `ez-php/contracts`.** The service provider is the only file with a framework dependency. All driver and value-object code is framework-agnostic.
- **Embedding driver selection is independent of the completion driver.** `ai.embedding_driver` is a separate config key from `ai.driver`, not derived from it — several completion providers (Anthropic, Mistral, Grok) have no embeddings API at all, so "same driver name for both" would be wrong by construction for half the supported providers. An application on `ai.driver=anthropic` sets `ai.embedding_driver=openai` (or `gemini`) explicitly if it wants embeddings.
- **`AiEmbeddingConfig` is currently unused.** Neither `EmbeddingClientInterface`'s actual signature (`embed(string, ?string $model)`) nor either embedding driver constructor takes it — both drivers take the same `OpenAiConfig`/`GeminiConfig` as their completion-driver counterparts and accept the model as a plain per-call `?string` override instead. This is pre-existing drift from an earlier design, not something introduced by `ai.embedding_driver` wiring; flagged here rather than silently deleted, since removing it is an audit-scope cleanup with its own test file to reconcile, not a byproduct of this feature.

---

## Testing approach

No external infrastructure required. All tests except `AiStreamEndToEndTest` (loopback only) use `FakeTransport` from `ez-php/http-client` to intercept HTTP calls and return synthetic responses. No real API keys, no network calls, no Docker services beyond the base PHP container.

- Driver tests verify URL construction, header serialization, request body structure, response parsing, finish reason mapping, and error handling — all via `FakeTransport`.
- Streaming tests use `HttpResponse` fixtures (whole body in one chunk) and `HttpStream::fake()` fixtures (events split mid-JSON, provider error events, missing terminators, a thrown `HttpStreamException`). Fixtures must be valid SSE: every event ends with a blank line, and Anthropic fixtures include `message_stop`.
- `EndToEnd/AiStreamEndToEndTest` is the only test with a socket: it starts `php -S` on 127.0.0.1 (no internet) and asserts tokens arrive spread over the server's pauses.
- Tool tests verify tool definition serialization, tool call response parsing, and tool result message round-trips for all three major providers.
- `AiServiceProviderTest` uses `FakeContainer` and `FakeConfig` (in `tests/Support/`) to test service provider wiring without the full framework container.
- `Ai::resetClient()` is called in `tearDown()` of `AiTest` and `AiServiceProviderTest` to clear static state between test classes.

---

## What does not belong in this module

| Concern | Where it belongs |
|---------|-----------------|
| HTTP transport, SSE decoding | `ez-php/http-client` (`HttpStream`, `SseDecoder`) |
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
