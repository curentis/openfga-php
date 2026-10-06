<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Api;

use Curentis\OpenFga\Client\Options\RetryOptions;
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
use Psr\Http\Message\ResponseInterface;

interface OpenFgaApiInterface
{
    public function withCallOptions(?RetryOptions $retry): self;

    /**
     * @param array<string, string> $headers
     */
    public function listStores(
        ?int $pageSize = null,
        ?string $continuationToken = null,
        ?string $name = null,
        array $headers = [],
    ): ListStoresResponse;

    /**
     * @param array<string, string> $headers
     */
    public function createStore(CreateStoreRequest $body, array $headers = []): CreateStoreResponse;

    /**
     * @param array<string, string> $headers
     */
    public function getStore(string $storeId, array $headers = []): GetStoreResponse;

    /**
     * @param array<string, string> $headers
     */
    public function deleteStore(string $storeId, array $headers = []): void;

    /**
     * @param array<string, string> $headers
     */
    public function readAuthorizationModels(
        string $storeId,
        ?int $pageSize = null,
        ?string $continuationToken = null,
        array $headers = [],
    ): ReadAuthorizationModelsResponse;

    /**
     * @param array<string, string> $headers
     */
    public function writeAuthorizationModel(
        string $storeId,
        WriteAuthorizationModelBody $body,
        array $headers = [],
    ): WriteAuthorizationModelResponse;

    /**
     * @param array<string, string> $headers
     */
    public function readAuthorizationModel(
        string $storeId,
        string $authorizationModelId,
        array $headers = [],
    ): ReadAuthorizationModelResponse;

    /**
     * @param array<string, string> $headers
     */
    public function read(string $storeId, ReadBody $body, array $headers = []): ReadResponse;

    /**
     * @param array<string, string> $headers
     */
    public function write(string $storeId, WriteBody $body, array $headers = [], bool $idempotent = false): WriteResponse;

    /**
     * @param array<string, string> $headers
     */
    public function readChanges(
        string $storeId,
        ?string $type = null,
        ?int $pageSize = null,
        ?string $continuationToken = null,
        ?string $startTime = null,
        array $headers = [],
    ): ReadChangesResponse;

    /**
     * @param array<string, string> $headers
     */
    public function check(string $storeId, CheckBody $body, array $headers = []): CheckResponse;

    /**
     * @param array<string, string> $headers
     */
    public function batchCheck(string $storeId, BatchCheckBody $body, array $headers = []): BatchCheckResponse;

    /**
     * @param array<string, string> $headers
     */
    public function expand(string $storeId, ExpandBody $body, array $headers = []): ExpandResponse;

    /**
     * @param array<string, string> $headers
     */
    public function listObjects(string $storeId, ListObjectsBody $body, array $headers = []): ListObjectsResponse;

    /**
     * @param array<string, string> $headers
     */
    public function streamedListObjects(string $storeId, ListObjectsBody $body, array $headers = []): ResponseInterface;

    /**
     * @param array<string, string> $headers
     */
    public function listUsers(string $storeId, ListUsersBody $body, array $headers = []): ListUsersResponse;

    /**
     * @param array<string, string> $headers
     */
    public function readAssertions(
        string $storeId,
        string $authorizationModelId,
        array $headers = [],
    ): ReadAssertionsResponse;

    /**
     * @param array<string, string> $headers
     */
    public function writeAssertions(
        string $storeId,
        string $authorizationModelId,
        WriteAssertionsBody $body,
        array $headers = [],
    ): void;
}
