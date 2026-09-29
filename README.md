# curentis/openfga-php

[![CI](https://github.com/curentis/openfga-php/actions/workflows/ci.yml/badge.svg)](https://github.com/curentis/openfga-php/actions/workflows/ci.yml)

PHP SDK for [OpenFGA](https://openfga.dev/) — fine-grained, relationship-based authorization (Zanzibar-style).

## Install

```bash
composer require curentis/openfga-php guzzlehttp/guzzle
```

Any PSR-18 HTTP client works (Guzzle, Symfony HttpClient, etc.).

## Requirements

- PHP **8.3**, **8.4**, and **8.5** (see [SUPPORTED_RUNTIMES.md](SUPPORTED_RUNTIMES.md))
- OpenFGA server **v1.10.0+**

## Quickstart

```php
use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;

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

A runnable script lives at [examples/quickstart.php](examples/quickstart.php).

### Highlights

- **Stores & models** — create stores, write/read authorization models.
- **Tuples** — `write`, `writeTuples`, `deleteTuples` with optional non-transactional chunking (`WriteOptions`) and conflict handling (`on_duplicate` / `on_missing`).
- **Checks** — `check`, chunked `batchCheck` (up to 50 per request), `listRelations`.
- **Queries** — `expand`, `listObjects`, streamed NDJSON `streamedListObjects`, `listUsers`.
- **Auth** — API token, OAuth client credentials, client assertion JWT; optional PSR-16 token cache.
- **Escape hatch** — `executeApiRequest` / `executeStreamedApiRequest` with the same transport, retries, and credentials.

## Development

```bash
composer install
composer check
docker compose -f docker-compose.test.yml up -d
composer test:integration
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and [AGENTS.md](AGENTS.md).

## Acknowledgements

- [OpenFGA](https://github.com/openfga/openfga) and the official language SDKs.
- [evansims/openfga-php](https://github.com/evansims/openfga-php) (archived) — design inspiration; this is a clean-room implementation.

## License

Apache-2.0 — see [LICENSE](LICENSE).
