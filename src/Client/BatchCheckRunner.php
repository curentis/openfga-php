<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApiInterface;
use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Response\ClientBatchCheckItemResult;
use Curentis\OpenFga\Client\Response\ClientBatchCheckResponse;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Http\ParallelTransportInterface;
use Curentis\OpenFga\Http\TransportCall;
use Curentis\OpenFga\Http\TransportInterface;
use Curentis\OpenFga\Model\BatchCheckBody;
use Curentis\OpenFga\Model\BatchCheckItem;
use Curentis\OpenFga\Model\BatchCheckResponse;
use Curentis\OpenFga\Model\BatchCheckSingleResult;
use Curentis\OpenFga\Model\CheckError;
use Curentis\OpenFga\Model\CheckRequestTupleKey;
use Curentis\OpenFga\Model\ConsistencyPreference;

final class BatchCheckRunner implements BatchCheckRunnerInterface
{
    private const string CORRELATION_ID = '/^[\w\d-]{1,36}$/';

    public function __construct(
        private readonly OpenFgaApiInterface $api,
        private readonly ?TransportInterface $transport = null,
    ) {}

    /**
     * @param list<ClientBatchCheckItem> $checks
     * @param array<string, string>      $headers
     */
    #[\Override]
    public function run(
        string $storeId,
        array $checks,
        BatchCheckOptions $options,
        ?string $authorizationModelId,
        ?ConsistencyPreference $consistency,
        array $headers,
        ?RetryOptions $retry = null,
    ): ClientBatchCheckResponse {
        if ($checks === []) {
            // Falling through also returns an empty response, so the early return is equivalent.
            /** @infection-ignore-all */
            return new ClientBatchCheckResponse([]);
        }

        $prepared = $this->prepareChecks($checks);
        /** @var positive-int $batchSize */
        $batchSize = $options->maxBatchSize;
        $chunks = array_chunk($prepared, $batchSize);
        $api = $retry !== null ? $this->api->withCallOptions($retry) : $this->api;
        $transport = $this->transport;

        if ($transport instanceof ParallelTransportInterface && $options->maxParallelRequests > 1) {
            $responses = $this->sendParallel(
                $transport,
                $storeId,
                $chunks,
                $authorizationModelId,
                $consistency,
                $headers,
                $options->maxParallelRequests,
                $retry,
            );
        } else {
            $responses = [];
            foreach ($chunks as $chunk) {
                $responses[] = $this->sendChunk($api, $storeId, $chunk, $authorizationModelId, $consistency, $headers);
            }
        }

        $results = [];
        foreach ($chunks as $index => $chunk) {
            $payload = $responses[$index];
            /** @var array<string, mixed> $map */
            $map = $payload->result ?? [];
            foreach ($chunk as $item) {
                $correlationId = $item['correlationId'];
                if (isset($map[$correlationId]) && is_array($map[$correlationId])) {
                    $single = BatchCheckSingleResult::fromArray($map[$correlationId]);
                } else {
                    $single = new BatchCheckSingleResult(
                        allowed: false,
                        error: new CheckError(message: 'missing result for correlation_id ' . $correlationId),
                    );
                }
                $results[] = new ClientBatchCheckItemResult($correlationId, $item['check'], $single);
            }
        }

        return new ClientBatchCheckResponse($results);
    }

    /**
     * @param list<array{correlationId: string, model: BatchCheckItem, check: ClientBatchCheckItem}> $chunk
     * @param array<string, string> $headers
     */
    private function sendChunk(
        OpenFgaApiInterface $api,
        string $storeId,
        array $chunk,
        ?string $authorizationModelId,
        ?ConsistencyPreference $consistency,
        array $headers,
    ): BatchCheckResponse {
        $response = $api->batchCheck(
            $storeId,
            $this->bodyForChunk($chunk, $authorizationModelId, $consistency),
            $this->chunkHeaders($headers),
        );

        return $response;
    }

    /**
     * @param list<list<array{correlationId: string, model: BatchCheckItem, check: ClientBatchCheckItem}>> $chunks
     * @param array<string, string> $headers
     *
     * @return list<BatchCheckResponse>
     */
    private function sendParallel(
        ParallelTransportInterface $transport,
        string $storeId,
        array $chunks,
        ?string $authorizationModelId,
        ?ConsistencyPreference $consistency,
        array $headers,
        int $maxParallel,
        ?RetryOptions $retry,
    ): array {
        $calls = [];
        foreach ($chunks as $chunk) {
            $calls[] = new TransportCall(
                'POST',
                '/stores/{store_id}/batch-check',
                ['store_id' => $storeId],
                [],
                $this->bodyForChunk($chunk, $authorizationModelId, $consistency)->toArray(),
                $this->chunkHeaders($headers),
                $storeId,
            );
        }

        /** @var positive-int $maxParallel */
        return array_map(
            static fn(array $decoded): BatchCheckResponse => BatchCheckResponse::fromArray($decoded),
            $transport->sendJsonAll($calls, $maxParallel, $retry),
        );
    }

    /**
     * @param list<array{correlationId: string, model: BatchCheckItem, check: ClientBatchCheckItem}> $chunk
     */
    private function bodyForChunk(
        array $chunk,
        ?string $authorizationModelId,
        ?ConsistencyPreference $consistency,
    ): BatchCheckBody {
        return new BatchCheckBody(
            checks: array_map(
                static fn(array $item): BatchCheckItem => $item['model'],
                $chunk,
            ),
            authorizationModelId: $authorizationModelId,
            consistency: $consistency,
        );
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function chunkHeaders(array $headers): array
    {
        return $headers + [
            'X-OpenFGA-Client-Bulk-Request-Id' => Uuid::v4(),
            'X-OpenFGA-Client-Method' => 'BatchCheck',
        ];
    }

    /**
     * @param list<ClientBatchCheckItem> $checks
     *
     * @return list<array{correlationId: string, model: BatchCheckItem, check: ClientBatchCheckItem}>
     */
    private function prepareChecks(array $checks): array
    {
        $seen = [];
        $prepared = [];

        foreach ($checks as $check) {
            $correlationId = $check->correlationId ?? Uuid::v4();
            if (preg_match(self::CORRELATION_ID, $correlationId) !== 1) {
                throw new FgaValidationException(sprintf(
                    'correlation_id "%s" must match ^[\w\d-]{1,36}$.',
                    $correlationId,
                ));
            }
            if (isset($seen[$correlationId])) {
                throw new FgaValidationException(sprintf('Duplicate correlation_id "%s" in batchCheck.', $correlationId));
            }
            $seen[$correlationId] = $correlationId;

            $prepared[] = [
                'correlationId' => $correlationId,
                'check' => $check,
                'model' => new BatchCheckItem(
                    correlationId: $correlationId,
                    tupleKey: new CheckRequestTupleKey(
                        user: $check->user,
                        relation: $check->relation,
                        object: $check->object,
                    ),
                    context: $check->context,
                    contextualTuples: $check->contextualTuples,
                ),
            ];
        }

        return $prepared;
    }
}
