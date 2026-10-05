<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Api;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Http\QueryParameterFilter;
use Curentis\OpenFga\Http\TransportInterface;
use Curentis\OpenFga\Model\BatchCheckBody;
use Curentis\OpenFga\Model\BatchCheckResponse;
use Curentis\OpenFga\Model\CheckBody;
use Curentis\OpenFga\Model\CheckResponse;
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
use Curentis\OpenFga\Model\WriteBody;
use Curentis\OpenFga\Model\WriteResponse;

final class OpenFgaApi implements OpenFgaApiInterface
{
    public function __construct(private readonly TransportInterface $transport) {}

    #[\Override]
    public function withCallOptions(?RetryOptions $retry): self
    {
        return new self(new CallOptionsTransport($this->transport, $retry));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function listStores(
        ?int $pageSize = null,
        ?string $continuationToken = null,
        ?string $name = null,
        array $headers = [],
    ): ListStoresResponse {
        return ListStoresResponse::fromArray($this->transport->sendJson(
            'GET',
            '/stores',
            [],
            QueryParameterFilter::omitNullAndEmpty([
                'page_size' => $pageSize,
                'continuation_token' => $continuationToken,
                'name' => $name,
            ]),
            null,
            $headers,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function createStore(CreateStoreRequest $body, array $headers = []): CreateStoreResponse
    {
        return CreateStoreResponse::fromArray($this->transport->sendJson(
            'POST',
            '/stores',
            [],
            [],
            $body->toArray(),
            $headers,
            null,
            null,
            false,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function getStore(string $storeId, array $headers = []): GetStoreResponse
    {
        return GetStoreResponse::fromArray($this->transport->sendJson(
            'GET',
            '/stores/{store_id}',
            ['store_id' => $storeId],
            [],
            null,
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function deleteStore(string $storeId, array $headers = []): void
    {
        $this->transport->send(
            'DELETE',
            '/stores/{store_id}',
            ['store_id' => $storeId],
            [],
            null,
            $headers,
            $storeId,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function readAuthorizationModels(
        string $storeId,
        ?int $pageSize = null,
        ?string $continuationToken = null,
        array $headers = [],
    ): ReadAuthorizationModelsResponse {
        return ReadAuthorizationModelsResponse::fromArray($this->transport->sendJson(
            'GET',
            '/stores/{store_id}/authorization-models',
            ['store_id' => $storeId],
            QueryParameterFilter::omitNullAndEmpty([
                'page_size' => $pageSize,
                'continuation_token' => $continuationToken,
            ]),
            null,
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function writeAuthorizationModel(
        string $storeId,
        WriteAuthorizationModelBody $body,
        array $headers = [],
    ): WriteAuthorizationModelResponse {
        return WriteAuthorizationModelResponse::fromArray($this->transport->sendJson(
            'POST',
            '/stores/{store_id}/authorization-models',
            ['store_id' => $storeId],
            [],
            $body->toArray(),
            $headers,
            $storeId,
            null,
            false,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function readAuthorizationModel(
        string $storeId,
        string $authorizationModelId,
        array $headers = [],
    ): ReadAuthorizationModelResponse {
        return ReadAuthorizationModelResponse::fromArray($this->transport->sendJson(
            'GET',
            '/stores/{store_id}/authorization-models/{id}',
            ['store_id' => $storeId, 'id' => $authorizationModelId],
            [],
            null,
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function read(
        string $storeId,
        ReadBody $body,
        array $headers = [],
    ): ReadResponse {
        return ReadResponse::fromArray($this->transport->sendJson(
            'POST',
            '/stores/{store_id}/read',
            ['store_id' => $storeId],
            [],
            $body->toArray(),
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function write(string $storeId, WriteBody $body, array $headers = [], bool $idempotent = false): WriteResponse
    {
        return WriteResponse::fromArray($this->transport->sendJson(
            'POST',
            '/stores/{store_id}/write',
            ['store_id' => $storeId],
            [],
            $body->toArray(),
            $headers,
            $storeId,
            null,
            $idempotent,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function readChanges(
        string $storeId,
        ?string $type = null,
        ?int $pageSize = null,
        ?string $continuationToken = null,
        ?string $startTime = null,
        array $headers = [],
    ): ReadChangesResponse {
        return ReadChangesResponse::fromArray($this->transport->sendJson(
            'GET',
            '/stores/{store_id}/changes',
            ['store_id' => $storeId],
            QueryParameterFilter::omitNullAndEmpty([
                'type' => $type,
                'page_size' => $pageSize,
                'continuation_token' => $continuationToken,
                'start_time' => $startTime,
            ]),
            null,
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function check(string $storeId, CheckBody $body, array $headers = []): CheckResponse
    {
        return CheckResponse::fromArray($this->transport->sendJson(
            'POST',
            '/stores/{store_id}/check',
            ['store_id' => $storeId],
            [],
            $body->toArray(),
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function batchCheck(string $storeId, BatchCheckBody $body, array $headers = []): BatchCheckResponse
    {
        return BatchCheckResponse::fromArray($this->transport->sendJson(
            'POST',
            '/stores/{store_id}/batch-check',
            ['store_id' => $storeId],
            [],
            $body->toArray(),
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function expand(string $storeId, ExpandBody $body, array $headers = []): ExpandResponse
    {
        return ExpandResponse::fromArray($this->transport->sendJson(
            'POST',
            '/stores/{store_id}/expand',
            ['store_id' => $storeId],
            [],
            $body->toArray(),
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function listObjects(string $storeId, ListObjectsBody $body, array $headers = []): ListObjectsResponse
    {
        return ListObjectsResponse::fromArray($this->transport->sendJson(
            'POST',
            '/stores/{store_id}/list-objects',
            ['store_id' => $storeId],
            [],
            $body->toArray(),
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function streamedListObjects(string $storeId, ListObjectsBody $body, array $headers = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->transport->send(
            'POST',
            '/stores/{store_id}/streamed-list-objects',
            ['store_id' => $storeId],
            [],
            $body->toArray(),
            $headers,
            $storeId,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function listUsers(string $storeId, ListUsersBody $body, array $headers = []): ListUsersResponse
    {
        return ListUsersResponse::fromArray($this->transport->sendJson(
            'POST',
            '/stores/{store_id}/list-users',
            ['store_id' => $storeId],
            [],
            $body->toArray(),
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function readAssertions(
        string $storeId,
        string $authorizationModelId,
        array $headers = [],
    ): ReadAssertionsResponse {
        return ReadAssertionsResponse::fromArray($this->transport->sendJson(
            'GET',
            '/stores/{store_id}/assertions/{authorization_model_id}',
            ['store_id' => $storeId, 'authorization_model_id' => $authorizationModelId],
            [],
            null,
            $headers,
            $storeId,
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function writeAssertions(
        string $storeId,
        string $authorizationModelId,
        WriteAssertionsBody $body,
        array $headers = [],
    ): void {
        $this->transport->send(
            'PUT',
            '/stores/{store_id}/assertions/{authorization_model_id}',
            ['store_id' => $storeId, 'authorization_model_id' => $authorizationModelId],
            [],
            $body->toArray(),
            $headers,
            $storeId,
            null,
            false,
        );
    }

}
