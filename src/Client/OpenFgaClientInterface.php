<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Options\PaginationOptions;
use Curentis\OpenFga\Client\Options\RequestOptions;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientListRelationsRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Client\Response\ClientBatchCheckResponse;
use Curentis\OpenFga\Client\Response\ClientListRelationsResponse;
use Curentis\OpenFga\Client\Response\ClientWriteResponse;
use Curentis\OpenFga\Model\Assertion;
use Curentis\OpenFga\Model\CheckResponse;
use Curentis\OpenFga\Model\ConsistencyPreference;
use Curentis\OpenFga\Model\CreateStoreResponse;
use Curentis\OpenFga\Model\ExpandBody;
use Curentis\OpenFga\Model\ExpandResponse;
use Curentis\OpenFga\Model\GetStoreResponse;
use Curentis\OpenFga\Model\ListObjectsBody;
use Curentis\OpenFga\Model\ListObjectsResponse;
use Curentis\OpenFga\Model\ListStoresResponse;
use Curentis\OpenFga\Model\ListUsersBody;
use Curentis\OpenFga\Model\ListUsersResponse;
use Curentis\OpenFga\Model\ReadAssertionsResponse;
use Curentis\OpenFga\Model\ReadAuthorizationModelResponse;
use Curentis\OpenFga\Model\ReadAuthorizationModelsResponse;
use Curentis\OpenFga\Model\ReadBody;
use Curentis\OpenFga\Model\ReadChangesResponse;
use Curentis\OpenFga\Model\ReadResponse;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;
use Curentis\OpenFga\Model\WriteAuthorizationModelResponse;
use Psr\Http\Message\ResponseInterface;

interface OpenFgaClientInterface
{
    public function withStoreId(string $storeId): OpenFgaClientInterface;

    public function withAuthorizationModelId(string $authorizationModelId): OpenFgaClientInterface;

    public function listStores(?PaginationOptions $page = null, ?string $name = null, ?RequestOptions $options = null): ListStoresResponse;

    public function createStore(string $name, ?RequestOptions $options = null): CreateStoreResponse;

    public function getStore(?RequestOptions $options = null): GetStoreResponse;

    public function deleteStore(?RequestOptions $options = null): void;

    public function readAuthorizationModels(?PaginationOptions $page = null, ?RequestOptions $options = null): ReadAuthorizationModelsResponse;

    public function writeAuthorizationModel(WriteAuthorizationModelBody $model, ?RequestOptions $options = null): WriteAuthorizationModelResponse;

    public function readAuthorizationModel(?RequestOptions $options = null): ReadAuthorizationModelResponse;

    public function readLatestAuthorizationModel(?RequestOptions $options = null): ?ReadAuthorizationModelResponse;

    public function read(?ReadBody $request = null, ?PaginationOptions $page = null, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ReadResponse;

    public function write(ClientWriteRequest $request, ?WriteOptions $write = null, ?RequestOptions $options = null): ClientWriteResponse;

    /** @param list<ClientTupleKey> $tuples */
    public function writeTuples(array $tuples, ?WriteOptions $write = null, ?RequestOptions $options = null): ClientWriteResponse;

    /** @param list<ClientTupleKeyWithoutCondition> $tuples */
    public function deleteTuples(array $tuples, ?WriteOptions $write = null, ?RequestOptions $options = null): ClientWriteResponse;

    public function readChanges(
        ?string $type = null,
        ?PaginationOptions $page = null,
        ?string $startTime = null,
        ?RequestOptions $options = null,
    ): ReadChangesResponse;

    public function check(ClientCheckRequest $request, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): CheckResponse;

    /** @param list<ClientBatchCheckItem> $checks */
    public function batchCheck(array $checks, ?BatchCheckOptions $batch = null, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ClientBatchCheckResponse;

    public function listRelations(ClientListRelationsRequest $request, ?BatchCheckOptions $batch = null, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ClientListRelationsResponse;

    public function expand(ExpandBody $body, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ExpandResponse;

    public function listObjects(ListObjectsBody $body, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ListObjectsResponse;

    /**
     * Sends the request when called; the returned generator only reads the stream.
     *
     * @return \Generator<int, string>
     */
    public function streamedListObjects(ListObjectsBody $body, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): \Generator;

    public function listUsers(ListUsersBody $body, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ListUsersResponse;

    public function readAssertions(?RequestOptions $options = null): ReadAssertionsResponse;

    /** @param list<Assertion> $assertions */
    public function writeAssertions(array $assertions, ?RequestOptions $options = null): void;

    /**
     * With `$idempotent = null`, GET/HEAD/OPTIONS and POSTs to the read endpoints (check, batch-check,
     * expand, list-objects, streamed-list-objects, list-users, read) retry server and network errors.
     * Anything else retries only 429.
     *
     * @param array<array-key, mixed> $pathParams
     * @param array<array-key, mixed> $query
     */
    public function executeApiRequest(
        string $method,
        string $path,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        ?RequestOptions $options = null,
        ?bool $idempotent = null,
    ): ResponseInterface;

    /**
     * @param array<array-key, mixed> $pathParams
     * @param array<array-key, mixed> $query
     *
     * Sends the request when called; the returned generator only reads the stream.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function executeStreamedApiRequest(
        string $method,
        string $path,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        ?RequestOptions $options = null,
        ?bool $idempotent = null,
    ): \Generator;
}
