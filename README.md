# curentis/openfga-php

[![CI](https://github.com/curentis/openfga-php/actions/workflows/ci.yml/badge.svg)](https://github.com/curentis/openfga-php/actions/workflows/ci.yml)

PHP SDK for [OpenFGA](https://openfga.dev/) — fine-grained, relationship-based authorization (Zanzibar-style).

This library targets production PHP applications: a typed high-level client (`OpenFgaClientInterface`), generated request/response models from the OpenFGA OpenAPI spec, PSR-18 HTTP with retries and error mapping, and extension points when you need custom write/batch-check behavior or a decorated client.

## Install

```bash
composer require curentis/openfga-php guzzlehttp/guzzle
```

Any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client works (Guzzle, Symfony HttpClient, etc.). Optional: [symfony/cache](https://symfony.com/doc/current/components/cache.html) for PSR-16 OAuth token caching across PHP-FPM workers.

## Requirements

| Component | Version |
|-----------|---------|
| PHP | **8.3**, **8.4**, or **8.5** ([SUPPORTED_RUNTIMES.md](SUPPORTED_RUNTIMES.md)) |
| OpenFGA server | **v1.10.0+** (integration tests also run against v1.21.0) |

## Quickstart

```php
use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\OpenFgaClientInterface;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;

/** @var OpenFgaClientInterface $fga */
$fga = OpenFgaClientFactory::create(new ClientConfiguration(
    apiUrl: getenv('FGA_API_URL') ?: 'http://localhost:8080',
));

$storeId = $fga->createStore('quickstart')->id;
$fga = $fga->withStoreId($storeId);

$modelId = $fga->writeAuthorizationModel(WriteAuthorizationModelBody::fromArray([
    'schema_version' => '1.1',
    'type_definitions' => [
        ['type' => 'user'],
        [
            'type' => 'document',
            'relations' => ['viewer' => ['this' => new stdClass()]],
            'metadata' => [
                'relations' => [
                    'viewer' => ['directly_related_user_types' => [['type' => 'user']]],
                ],
            ],
        ],
    ],
]))->authorizationModelId;
$fga = $fga->withAuthorizationModelId($modelId);

$fga->write(new ClientWriteRequest(writes: [
    new ClientTupleKey('user:anne', 'viewer', 'document:roadmap'),
]));

var_dump($fga->check(new ClientCheckRequest('user:anne', 'viewer', 'document:roadmap'))->allowed); // true
```

```bash
export FGA_API_URL=http://localhost:8080
php examples/quickstart.php
```

## Configuration

`ClientConfiguration` is the single place to wire URL, store context, credentials, HTTP stack, and optional factories:

| Option | Description |
|--------|-------------|
| `apiUrl` | OpenFGA base URL (default `http://localhost:8080`) |
| `storeId` / `authorizationModelId` | Default ULIDs for store-scoped APIs (optional) |
| `credentials` | `ApiToken`, `ClientCredentials`, `ClientAssertion`, or `NoCredentials` |
| `tokenCache` | Optional PSR-16 cache for OAuth access tokens |
| `httpClient`, `requestFactory`, `streamFactory`, `uriFactory` | Override PSR-18 / PSR-17 discovery |
| `retry` | `RetryOptions` (max retries, backoff, wall-clock deadline, per-attempt cap) |
| `defaultHeaders` | Headers sent on every request |
| `componentFactory` | Custom write/batch-check runners and consistency body factory |
| `tokenCacheKey` | Optional 32-byte sodium key that encrypts cached OAuth tokens |
| `telemetry` | Optional PSR-3 logger and PSR-14 dispatcher |

Immutable helpers `withStoreId()` / `withAuthorizationModelId()` return a new client; per-call overrides use `RequestOptions` (store, model, headers). Details: [docs/getting-started.md](docs/getting-started.md).

### Authentication (summary)

```php
use Curentis\OpenFga\Credentials\ApiToken;
use Curentis\OpenFga\Credentials\ClientCredentials;

// Bearer API token (hosted OpenFGA)
new ClientConfiguration(credentials: new ApiToken($apiToken));

// OAuth2 client credentials
new ClientConfiguration(credentials: new ClientCredentials(
    clientId: '...',
    clientSecret: '...',
    apiTokenIssuer: 'https://auth.example.com',
    apiAudience: 'https://api.us1.fga.dev/',
));
```

See [docs/authentication.md](docs/authentication.md) and [examples/authentication_api_token.php](examples/authentication_api_token.php).

## Features

### Stores and authorization models

Create and manage stores; write and read authorization models; `readLatestAuthorizationModel()` for the newest model id in a store.

### Tuples (writes)

- `write()`, `writeTuples()`, `deleteTuples()` with transactional semantics by default.
- **Non-transactional** mode and **chunking** via `WriteOptions` + `TransactionOptions`.
- **Conflict policies** via `ConflictOptions` (`on_duplicate`, `on_missing`).

### Checks and batch operations

- `check()` with optional `ConsistencyPreference`.
- `batchCheck()` — automatic chunking (default max **50** checks per HTTP request, configurable).
- `listRelations()` — batch-check a list of relation names for one user/object pair.

### Queries

- `read()`, `readChanges()`, `expand()`, `listObjects()`, `listUsers()`.
- `streamedListObjects()` — NDJSON stream of object ids.

### Assertions and low-level API

- `readAssertions()` / `writeAssertions()` for model tests.
- `executeApiRequest()` and `executeStreamedApiRequest()` — same transport, retries, and credentials as the rest of the SDK.

Full API walkthrough: [docs/client-operations.md](docs/client-operations.md).

## Customization

Depend on interfaces in your app; swap implementations without forking the SDK:

| Interface | Typical use |
|-----------|-------------|
| `OpenFgaClientInterface` | Application entry point; decorators for logging/metrics |
| `OpenFgaClientFactoryInterface` | Custom client wiring |
| `ClientComponentFactoryInterface` | Custom `WriteRunner` / `BatchCheckRunner` / consistency mapping |
| `OpenFgaApiInterface` / `TransportInterface` | Advanced HTTP or API wrapping |

```php
$fga = OpenFgaClientFactory::create(new ClientConfiguration(
    componentFactory: new MyComponentFactory(),
));
```

Guide: [docs/customization.md](docs/customization.md) · Example: [examples/custom_components.php](examples/custom_components.php).

## Documentation and examples

| Resource | Description |
|----------|-------------|
| [docs/README.md](docs/README.md) | Documentation index |
| [docs/getting-started.md](docs/getting-started.md) | Install, configuration, first checks |
| [docs/authentication.md](docs/authentication.md) | Tokens and OAuth |
| [docs/client-operations.md](docs/client-operations.md) | Writes, batch check, consistency, raw requests |
| [docs/customization.md](docs/customization.md) | Extension interfaces |
| [docs/error-handling.md](docs/error-handling.md) | Exception hierarchy and partial writes |
| [docs/production.md](docs/production.md) | Timeouts, retries, token cache, consistency |
| [examples/README.md](examples/README.md) | Runnable scripts |

## Development

```bash
composer install
composer check          # style, static analysis, unit + contract + architecture tests
composer test:unit
composer test:integration   # requires OpenFGA (see docker-compose.test.yml)
```

On PHP 8.3 with locked dependencies, CI also enforces **100%** line coverage (hand-written `src/`, excluding generated `src/Model`) and **100%** Infection MSI.

Codegen from the vendored OpenAPI spec:

```bash
composer codegen        # regenerate src/Model
composer codegen:check  # verify models match spec (CI)
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and [AGENTS.md](AGENTS.md).

## Acknowledgements

- [OpenFGA](https://github.com/openfga/openfga) and the official language SDKs.
- [evansims/openfga-php](https://github.com/evansims/openfga-php) (archived) — design inspiration; this is a clean-room implementation.

## License

Apache-2.0 — see [LICENSE](LICENSE).
