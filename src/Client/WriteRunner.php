<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApiInterface;
use Curentis\OpenFga\Client\Options\ConflictOptions;
use Curentis\OpenFga\Client\Options\OnDuplicateWrites;
use Curentis\OpenFga\Client\Options\OnMissingDeletes;
use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Client\Response\ClientWriteResponse;
use Curentis\OpenFga\Client\Response\ClientWriteTupleResult;
use Curentis\OpenFga\Exception\FgaApiNotFoundException;
use Curentis\OpenFga\Exception\FgaApiValidationException;
use Curentis\OpenFga\Exception\FgaPartialWriteException;
use Curentis\OpenFga\Exception\FgaValidationException;

final class WriteRunner implements WriteRunnerInterface
{
    public function __construct(private readonly OpenFgaApiInterface $api) {}

    /**
     * @param array<string, string> $headers
     */
    #[\Override]
    public function run(
        string $storeId,
        ClientWriteRequest $request,
        ?string $authorizationModelId,
        WriteOptions $writeOptions,
        array $headers,
        ?RetryOptions $retry = null,
    ): ClientWriteResponse {
        if ($request->writes === [] && $request->deletes === []) {
            throw new FgaValidationException('write request must include at least one write or delete.');
        }

        $api = $retry !== null ? $this->api->withCallOptions($retry) : $this->api;
        $idempotent = $this->isIdempotent($request, $writeOptions->conflict);

        if (!$writeOptions->transaction->disable) {
            $body = ClientRequestMapper::toWriteBody($request, $authorizationModelId, $writeOptions->conflict);
            $response = $api->write($storeId, $body, $headers + ['X-OpenFGA-Client-Method' => 'Write'], $idempotent);

            return new ClientWriteResponse($response);
        }

        $tupleResults = [];
        $lastResponse = null;
        /** @var positive-int $maxPerChunk */
        $maxPerChunk = $writeOptions->maxPerChunk;

        foreach ($this->chunkTuples($request, $maxPerChunk) as $chunk) {
            $body = ClientRequestMapper::toWriteBody($chunk, $authorizationModelId, $writeOptions->conflict);
            $chunkIdempotent = $this->isIdempotent($chunk, $writeOptions->conflict);
            try {
                $lastResponse = $api->write(
                    $storeId,
                    $body,
                    $headers + ['X-OpenFGA-Client-Method' => 'Write'],
                    $chunkIdempotent,
                );
                foreach ($this->successResults($chunk) as $result) {
                    $tupleResults[] = $result;
                }
            } catch (FgaApiValidationException|FgaApiNotFoundException $exception) {
                foreach ($this->failureResults($chunk, $exception) as $result) {
                    $tupleResults[] = $result;
                }
            } catch (\Throwable $exception) {
                throw new FgaPartialWriteException($tupleResults, $exception);
            }
        }

        return new ClientWriteResponse($lastResponse, $tupleResults);
    }

    private function isIdempotent(ClientWriteRequest $request, ConflictOptions $conflict): bool
    {
        $writesOk = $request->writes === [] || $conflict->onDuplicateWrites === OnDuplicateWrites::Ignore;
        $deletesOk = $request->deletes === [] || $conflict->onMissingDeletes === OnMissingDeletes::Ignore;

        return $writesOk && $deletesOk;
    }

    /**
     * @param positive-int $maxPerChunk
     *
     * @return list<ClientWriteRequest>
     */
    private function chunkTuples(ClientWriteRequest $request, int $maxPerChunk): array
    {
        // Deletes go first so a delete-then-write of the same tuple (a replace) leaves the tuple written.
        $chunks = [];
        foreach (array_chunk($request->deletes, $maxPerChunk) as $deleteChunk) {
            $chunks[] = new ClientWriteRequest(deletes: $deleteChunk);
        }
        foreach (array_chunk($request->writes, $maxPerChunk) as $writeChunk) {
            $chunks[] = new ClientWriteRequest(writes: $writeChunk);
        }

        return $chunks;
    }

    /**
     * @return list<ClientWriteTupleResult>
     */
    private function successResults(ClientWriteRequest $chunk): array
    {
        $results = [];
        foreach ($chunk->deletes as $tuple) {
            $results[] = $this->tupleResult($tuple, 'delete', true, null);
        }
        foreach ($chunk->writes as $tuple) {
            $results[] = $this->tupleResult($tuple, 'write', true, null);
        }

        return $results;
    }

    /**
     * @return list<ClientWriteTupleResult>
     */
    private function failureResults(ClientWriteRequest $chunk, \Throwable $error): array
    {
        $results = [];
        foreach ($chunk->deletes as $tuple) {
            $results[] = $this->tupleResult($tuple, 'delete', false, $error);
        }
        foreach ($chunk->writes as $tuple) {
            $results[] = $this->tupleResult($tuple, 'write', false, $error);
        }

        return $results;
    }

    private function tupleResult(
        ClientTupleKey|ClientTupleKeyWithoutCondition $tuple,
        string $operation,
        bool $success,
        ?\Throwable $error,
    ): ClientWriteTupleResult {
        return new ClientWriteTupleResult(
            user: $tuple->user,
            relation: $tuple->relation,
            object: $tuple->object,
            operation: $operation,
            success: $success,
            error: $error,
        );
    }
}
