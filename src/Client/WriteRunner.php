<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApiInterface;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Client\Response\ClientWriteResponse;
use Curentis\OpenFga\Client\Response\ClientWriteTupleResult;

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
    ): ClientWriteResponse {
        if (!$writeOptions->transaction->disable) {
            $body = ClientRequestMapper::toWriteBody($request, $authorizationModelId, $writeOptions->conflict);
            $response = $this->api->write($storeId, $body, $headers + ['X-OpenFGA-Client-Method' => 'Write']);

            return new ClientWriteResponse($response);
        }

        $tupleResults = [];
        $lastResponse = null;
        /** @var positive-int $maxPerChunk */
        $maxPerChunk = $writeOptions->maxPerChunk;

        foreach ($this->chunkTuples($request, $maxPerChunk) as $chunk) {
            $body = ClientRequestMapper::toWriteBody($chunk, $authorizationModelId, $writeOptions->conflict);
            try {
                $lastResponse = $this->api->write(
                    $storeId,
                    $body,
                    $headers + ['X-OpenFGA-Client-Method' => 'Write'],
                );
                $tupleResults = array_merge($tupleResults, $this->successResults($chunk));
            } catch (\Throwable $exception) {
                $tupleResults = array_merge($tupleResults, $this->failureResults($chunk, $exception));
            }
        }

        return new ClientWriteResponse($lastResponse, $tupleResults);
    }

    /**
     * @param positive-int $maxPerChunk
     *
     * @return list<ClientWriteRequest>
     */
    private function chunkTuples(ClientWriteRequest $request, int $maxPerChunk): array
    {
        $chunks = [];
        $writes = $request->writes;
        $deletes = $request->deletes;

        foreach (array_chunk($writes, $maxPerChunk) as $writeChunk) {
            $chunks[] = new ClientWriteRequest(writes: $writeChunk);
        }
        foreach (array_chunk($deletes, $maxPerChunk) as $deleteChunk) {
            $chunks[] = new ClientWriteRequest(deletes: $deleteChunk);
        }

        if ($chunks === []) {
            return [new ClientWriteRequest()];
        }

        return $chunks;
    }

    /**
     * @return list<ClientWriteTupleResult>
     */
    private function successResults(ClientWriteRequest $chunk): array
    {
        $results = [];
        foreach ($chunk->writes as $tuple) {
            $results[] = $this->tupleResult($tuple, 'write', true, null);
        }
        foreach ($chunk->deletes as $tuple) {
            $results[] = $this->tupleResult($tuple, 'delete', true, null);
        }

        return $results;
    }

    /**
     * @return list<ClientWriteTupleResult>
     */
    private function failureResults(ClientWriteRequest $chunk, \Throwable $error): array
    {
        $results = [];
        foreach ($chunk->writes as $tuple) {
            $results[] = $this->tupleResult($tuple, 'write', false, $error);
        }
        foreach ($chunk->deletes as $tuple) {
            $results[] = $this->tupleResult($tuple, 'delete', false, $error);
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
