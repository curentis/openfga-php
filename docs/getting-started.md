# Getting started

## Install

```bash
composer require curentis/openfga-php guzzlehttp/guzzle
```

You need any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client and matching PSR-17 factories. Guzzle is a common choice; Symfony HttpClient also works.

PHP **8.3+** is required. See [SUPPORTED_RUNTIMES.md](../SUPPORTED_RUNTIMES.md).

## Create a client

The entry point is `OpenFgaClientFactory::create()` with a `ClientConfiguration`:

```php
use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;

$fga = OpenFgaClientFactory::create(new ClientConfiguration(
    apiUrl: 'https://api.us1.fga.dev',
    storeId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    authorizationModelId: '01HZZZZZZZZZZZZZZZZZZZZZZZ',
));
```

| Setting | Purpose |
|---------|---------|
| `apiUrl` | OpenFGA HTTP base URL (default `http://localhost:8080`) |
| `storeId` | ULID of the target store (optional until you call store-scoped APIs) |
| `authorizationModelId` | Default model for check/write APIs (optional) |
| `credentials` | `ApiToken`, `ClientCredentials`, `ClientAssertion`, or `NoCredentials` (default) |

Type-hint against `OpenFgaClientInterface` in your application code so you can swap implementations or decorators.

## Immutable store and model context

`withStoreId()` and `withAuthorizationModelId()` return a new client instance; the original is unchanged:

```php
$fgaForStoreA = $fga->withStoreId('01ARZ3NDEKTSV4RRFFQ69G5FAV');
$fgaForStoreB = $fga->withStoreId('01J00000000000000000000000');
```

Per-request overrides are available via `RequestOptions` (see [Client operations](client-operations.md)).

## End-to-end flow

1. **Create a store** (or use an existing store id).
2. **Write an authorization model** and keep the returned model id.
3. **Write relationship tuples**.
4. **Check** whether a user has a relation on an object.

```php
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;

$storeId = $fga->createStore('my-app')->id;
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

$allowed = $fga->check(new ClientCheckRequest(
    'user:anne',
    'viewer',
    'document:roadmap',
))->allowed;
```

Run the same flow locally:

```bash
export FGA_API_URL=http://localhost:8080
php examples/quickstart.php
```

## Development against OpenFGA

```bash
composer install
docker compose -f docker-compose.test.yml up -d
composer test:integration
```

See [CONTRIBUTING.md](../CONTRIBUTING.md) for the full contributor workflow.
