# Client operations

The high-level client is `OpenFgaClientInterface`. It wraps the generated OpenFGA models and adds helpers (batching, list relations, non-transactional writes, streamed list objects).

## Writes and deletes

**Transactional write** (default) — one OpenFGA `Write` API call per `write()`:

```php
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;

$fga->write(new ClientWriteRequest(
    writes: [new ClientTupleKey('user:anne', 'viewer', 'document:1')],
    deletes: [new ClientTupleKeyWithoutCondition('user:bob', 'viewer', 'document:1')],
));

$fga->writeTuples([new ClientTupleKey('user:anne', 'editor', 'document:2')]);
$fga->deleteTuples([new ClientTupleKeyWithoutCondition('user:anne', 'editor', 'document:2')]);
```

**Non-transactional / chunked writes** — disable the transaction and set chunk size:

```php
use Curentis\OpenFga\Client\Options\TransactionOptions;
use Curentis\OpenFga\Client\Options\WriteOptions;

$fga->write(
    $request,
    new WriteOptions(
        transaction: new TransactionOptions(disable: true),
        maxPerChunk: 10,
    ),
);
```

Conflict behavior (`on_duplicate`, `on_missing`) is configured via `ConflictOptions` on `WriteOptions`.

## Checks and batch check

Single check:

```php
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Model\ConsistencyPreference;

$fga->check(
    new ClientCheckRequest('user:anne', 'viewer', 'document:1'),
    ConsistencyPreference::HIGHER_CONSISTENCY,
);
```

Batch check (automatic chunking, default max 50 per HTTP request):

```php
use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;

$response = $fga->batchCheck(
    [
        new ClientBatchCheckItem('user:anne', 'viewer', 'document:1', correlationId: 'c1'),
        new ClientBatchCheckItem('user:bob', 'viewer', 'document:1', correlationId: 'c2'),
    ],
    new BatchCheckOptions(maxBatchSize: 25),
);

foreach ($response->results as $index => $result) {
    // $result->allowed, $result->error, etc.
}
```

**List relations** — batch-checks each relation name and returns those that are allowed:

```php
use Curentis\OpenFga\Client\Request\ClientListRelationsRequest;

$fga->listRelations(new ClientListRelationsRequest(
    user: 'user:anne',
    object: 'document:1',
    relations: ['viewer', 'editor', 'owner'],
));
```

Example: [examples/batch_check.php](../examples/batch_check.php).

## Read, expand, list objects / users

```php
use Curentis\OpenFga\Model\ExpandBody;
use Curentis\OpenFga\Model\ExpandRequestTupleKey;
use Curentis\OpenFga\Model\ListObjectsBody;
use Curentis\OpenFga\Model\ReadBody;

$fga->read(new ReadBody(tupleKey: /* ... */));

$fga->expand(new ExpandBody(
    tupleKey: new ExpandRequestTupleKey(object: 'document:1', relation: 'viewer'),
));

foreach ($fga->streamedListObjects(new ListObjectsBody(
    user: 'user:anne',
    relation: 'viewer',
    type: 'document',
)) as $objectId) {
    echo $objectId, PHP_EOL;
}
```

Pass `ConsistencyPreference` on expand, list objects, list users, check, and batch check when you need stronger read consistency.

## Per-request options

`RequestOptions` can override `storeId`, `authorizationModelId`, add headers, or adjust retry for a single call:

```php
use Curentis\OpenFga\Client\Options\RequestOptions;

$fga->getStore(new RequestOptions(
    storeId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    headers: ['X-Request-Source' => 'billing-service'],
));
```

## Raw API escape hatch

For endpoints or fields not yet wrapped by the client, use the same transport (retries, auth, errors):

```php
$response = $fga->executeApiRequest(
    'GET',
    '/stores/{store_id}/authorization-models',
    ['store_id' => $storeId],
    ['page_size' => 10],
);

foreach ($fga->executeStreamedApiRequest('POST', '/some/streamed/path', ...) as $line) {
    // NDJSON object per line
}
```

Lower-level access: depend on `OpenFgaApiInterface` or `TransportInterface` when building custom stacks (see [Customization](customization.md)).
