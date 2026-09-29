<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApi;
use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Options\PaginationOptions;
use Curentis\OpenFga\Client\Options\RequestOptions;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientListRelationsRequest;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Client\Response\ClientBatchCheckResponse;
use Curentis\OpenFga\Client\Response\ClientListRelationsResponse;
use Curentis\OpenFga\Client\Response\ClientWriteResponse;
use Curentis\OpenFga\Exception\FgaRequiredParamException;
use Curentis\OpenFga\Http\NdjsonStream;
use Curentis\OpenFga\Http\PathTemplate;
use Curentis\OpenFga\Http\Transport;
use Curentis\OpenFga\Model\CheckResponse;
use Curentis\OpenFga\Model\ConsistencyPreference;
use Curentis\OpenFga\Model\CreateStoreRequest;
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
use Curentis\OpenFga\Model\WriteAssertionsBody;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;
use Curentis\OpenFga\Model\WriteAuthorizationModelResponse;
use Psr\Http\Message\ResponseInterface;

final class OpenFgaClient implements OpenFgaClientInterface
{
    public function __construct(
        private readonly ClientConfiguration $configuration,
        private readonly OpenFgaApi $api,
        private readonly Transport $transport,
    ) {}

    #[\Override]
    public function withStoreId(string $storeId): self
    {
        return new self($this->configuration->withStoreId($storeId), $this->api, $this->transport);
    }

    #[\Override]
    public function withAuthorizationModelId(string $authorizationModelId): self
    {
        return new self($this->configuration->withAuthorizationModelId($authorizationModelId), $this->api, $this->transport);
    }

    #[\Override]
    public function listStores(?PaginationOptions $page = null, ?string $name = null, ?RequestOptions $options = null): ListStoresResponse
    {
        return $this->api->listStores(
            $page?->pageSize,
            $page?->continuationToken,
            $name,
            $this->headers($options),
        );
    }

    #[\Override]
    public function createStore(string $name, ?RequestOptions $options = null): CreateStoreResponse
    {
        return $this->api->createStore(new CreateStoreRequest(name: $name), $this->headers($options));
    }

    #[\Override]
    public function getStore(?RequestOptions $options = null): GetStoreResponse
    {
        $storeId = $this->requireStoreId($options);

        return $this->api->getStore($storeId, $this->headers($options));
    }

    #[\Override]
    public function deleteStore(?RequestOptions $options = null): void
    {
        $this->api->deleteStore($this->requireStoreId($options), $this->headers($options));
    }

    #[\Override]
    public function readAuthorizationModels(?PaginationOptions $page = null, ?RequestOptions $options = null): ReadAuthorizationModelsResponse
    {
        return $this->api->readAuthorizationModels(
            $this->requireStoreId($options),
            $page?->pageSize,
            $page?->continuationToken,
            $this->headers($options),
        );
    }

    #[\Override]
    public function writeAuthorizationModel(WriteAuthorizationModelBody $model, ?RequestOptions $options = null): WriteAuthorizationModelResponse
    {
        return $this->api->writeAuthorizationModel($this->requireStoreId($options), $model, $this->headers($options));
    }

    #[\Override]
    public function readAuthorizationModel(?RequestOptions $options = null): ReadAuthorizationModelResponse
    {
        $storeId = $this->requireStoreId($options);
        $modelId = $this->requireAuthorizationModelId($options);

        return $this->api->readAuthorizationModel($storeId, $modelId, $this->headers($options));
    }

    #[\Override]
    public function readLatestAuthorizationModel(?RequestOptions $options = null): ?ReadAuthorizationModelResponse
    {
        $list = $this->readAuthorizationModels(new PaginationOptions(pageSize: 1), $options);
        if ($list->authorizationModels === []) {
            return null;
        }

        $latestId = $list->authorizationModels[0]->id;
        $storeId = $this->requireStoreId($options);

        return $this->api->readAuthorizationModel($storeId, $latestId, $this->headers($options));
    }

    #[\Override]
    public function read(?ReadBody $request = null, ?PaginationOptions $page = null, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ReadResponse
    {
        $body = $request ?? new ReadBody();
        $consistencyValue = $consistency !== null ? $consistency->value : $body->consistency;
        $pageSize = $page !== null ? $page->pageSize : $body->pageSize;
        $continuationToken = $page !== null ? $page->continuationToken : $body->continuationToken;
        $body = new ReadBody(
            consistency: $consistencyValue,
            continuationToken: $continuationToken,
            pageSize: $pageSize,
            tupleKey: $body->tupleKey,
        );

        return $this->api->read($this->requireStoreId($options), $body, $this->headers($options));
    }

    #[\Override]
    public function write(ClientWriteRequest $request, ?WriteOptions $write = null, ?RequestOptions $options = null): ClientWriteResponse
    {
        return (new WriteRunner($this->api))->run(
            $this->requireStoreId($options),
            $request,
            $this->authorizationModelId($options),
            $write ?? new WriteOptions(),
            $this->headers($options),
        );
    }

    #[\Override]
    public function writeTuples(array $tuples, ?WriteOptions $write = null, ?RequestOptions $options = null): ClientWriteResponse
    {
        return $this->write(new ClientWriteRequest(writes: $tuples), $write, $options);
    }

    #[\Override]
    public function deleteTuples(array $tuples, ?WriteOptions $write = null, ?RequestOptions $options = null): ClientWriteResponse
    {
        return $this->write(new ClientWriteRequest(deletes: $tuples), $write, $options);
    }

    #[\Override]
    public function readChanges(
        ?string $type = null,
        ?PaginationOptions $page = null,
        ?string $startTime = null,
        ?RequestOptions $options = null,
    ): ReadChangesResponse {
        return $this->api->readChanges(
            $this->requireStoreId($options),
            $type,
            $page?->pageSize,
            $page?->continuationToken,
            $startTime,
            $this->headers($options),
        );
    }

    #[\Override]
    public function check(ClientCheckRequest $request, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): CheckResponse
    {
        $body = ClientRequestMapper::toCheckBody($request, $this->authorizationModelId($options), $consistency);

        return $this->api->check($this->requireStoreId($options), $body, $this->headers($options));
    }

    #[\Override]
    public function batchCheck(array $checks, ?BatchCheckOptions $batch = null, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ClientBatchCheckResponse
    {
        return (new BatchCheckRunner($this->api))->run(
            $this->requireStoreId($options),
            $checks,
            $batch ?? new BatchCheckOptions(),
            $this->authorizationModelId($options),
            $consistency,
            $this->headers($options),
        );
    }

    #[\Override]
    public function listRelations(ClientListRelationsRequest $request, ?BatchCheckOptions $batch = null, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ClientListRelationsResponse
    {
        $checks = [];
        foreach ($request->relations as $relation) {
            $checks[] = new ClientBatchCheckItem(
                user: $request->user,
                relation: $relation,
                object: $request->object,
                correlationId: $relation,
            );
        }

        $batchResponse = $this->batchCheck($checks, $batch, $consistency, $options);
        $allowed = [];
        foreach ($request->relations as $index => $relation) {
            $result = $batchResponse->results[$index] ?? null;
            if ($result !== null && $result->allowed === true) {
                $allowed[] = $relation;
            }
        }

        return new ClientListRelationsResponse($allowed);
    }

    #[\Override]
    public function expand(ExpandBody $body, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ExpandResponse
    {
        if ($consistency !== null) {
            $body = new ExpandBody(
                tupleKey: $body->tupleKey,
                authorizationModelId: $body->authorizationModelId ?? $this->authorizationModelId($options),
                consistency: $consistency->value,
                contextualTuples: $body->contextualTuples,
            );
        }

        return $this->api->expand($this->requireStoreId($options), $body, $this->headers($options));
    }

    #[\Override]
    public function listObjects(ListObjectsBody $body, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ListObjectsResponse
    {
        if ($consistency !== null) {
            $body = new ListObjectsBody(
                user: $body->user,
                relation: $body->relation,
                type: $body->type,
                contextualTuples: $body->contextualTuples,
                context: $body->context,
                consistency: $consistency->value,
            );
        }

        return $this->api->listObjects($this->requireStoreId($options), $body, $this->headers($options));
    }

    /**
     * @return \Generator<int, string>
     */
    #[\Override]
    public function streamedListObjects(ListObjectsBody $body, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): \Generator
    {
        if ($consistency !== null) {
            $body = new ListObjectsBody(
                user: $body->user,
                relation: $body->relation,
                type: $body->type,
                contextualTuples: $body->contextualTuples,
                context: $body->context,
                consistency: $consistency->value,
            );
        }

        $response = $this->api->streamedListObjects($this->requireStoreId($options), $body, $this->headers($options));
        foreach (NdjsonStream::decode($response->getBody()) as $line) {
            /** @var mixed $maybeResult */
            $maybeResult = $line['result'] ?? null;
            if (is_array($maybeResult) && isset($maybeResult['object']) && is_string($maybeResult['object'])) {
                yield $maybeResult['object'];
            }
        }
    }

    #[\Override]
    public function listUsers(ListUsersBody $body, ?ConsistencyPreference $consistency = null, ?RequestOptions $options = null): ListUsersResponse
    {
        if ($consistency !== null) {
            $body = new ListUsersBody(
                object: $body->object,
                relation: $body->relation,
                userFilters: $body->userFilters,
                contextualTuples: $body->contextualTuples,
                context: $body->context,
                consistency: $consistency->value,
            );
        }

        return $this->api->listUsers($this->requireStoreId($options), $body, $this->headers($options));
    }

    #[\Override]
    public function readAssertions(?RequestOptions $options = null): ReadAssertionsResponse
    {
        return $this->api->readAssertions(
            $this->requireStoreId($options),
            $this->requireAuthorizationModelId($options),
            $this->headers($options),
        );
    }

    #[\Override]
    public function writeAssertions(array $assertions, ?RequestOptions $options = null): void
    {
        $this->api->writeAssertions(
            $this->requireStoreId($options),
            $this->requireAuthorizationModelId($options),
            new WriteAssertionsBody(assertions: $assertions),
            $this->headers($options),
        );
    }

    /**
     * @param array<array-key, mixed> $pathParams
     * @param array<array-key, mixed> $query
     */
    #[\Override]
    public function executeApiRequest(
        string $method,
        string $path,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        ?RequestOptions $options = null,
    ): ResponseInterface {
        $normalizedPathParams = $this->normalizeScalarParams($pathParams);
        $normalizedQuery = $this->normalizeScalarParams($query);
        PathTemplate::expand($path, $normalizedPathParams);

        return $this->transport->send(
            $method,
            $path,
            $normalizedPathParams,
            $normalizedQuery,
            $body,
            $this->headers($options),
            $this->storeId($options),
        );
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    #[\Override]
    public function executeStreamedApiRequest(
        string $method,
        string $path,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        ?RequestOptions $options = null,
    ): \Generator {
        $response = $this->executeApiRequest($method, $path, $pathParams, $query, $body, $options);

        yield from NdjsonStream::decode($response->getBody());
    }

    private function requireStoreId(?RequestOptions $options): string
    {
        $storeId = $this->storeId($options);
        if ($storeId === null || $storeId === '') {
            throw new FgaRequiredParamException('storeId');
        }

        return $storeId;
    }

    private function requireAuthorizationModelId(?RequestOptions $options): string
    {
        $modelId = $this->authorizationModelId($options);
        if ($modelId === null || $modelId === '') {
            throw new FgaRequiredParamException('authorizationModelId');
        }

        return $modelId;
    }

    private function storeId(?RequestOptions $options): ?string
    {
        if ($options !== null && $options->storeId !== null) {
            return $options->storeId;
        }

        return $this->configuration->storeId;
    }

    private function authorizationModelId(?RequestOptions $options): ?string
    {
        if ($options !== null && $options->authorizationModelId !== null) {
            return $options->authorizationModelId;
        }

        return $this->configuration->authorizationModelId;
    }

    /**
     * @return array<string, string>
     */
    private function headers(?RequestOptions $options): array
    {
        return $options !== null ? $options->headers : [];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, bool|float|int|string|null>
     *
     * @psalm-suppress MixedAssignment
     */
    private function normalizeScalarParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
