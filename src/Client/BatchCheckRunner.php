<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApiInterface;
use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Response\ClientBatchCheckResponse;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Model\BatchCheckBody;
use Curentis\OpenFga\Model\BatchCheckItem;
use Curentis\OpenFga\Model\BatchCheckSingleResult;
use Curentis\OpenFga\Model\CheckRequestTupleKey;
use Curentis\OpenFga\Model\ConsistencyPreference;

final class BatchCheckRunner implements BatchCheckRunnerInterface
{
    public function __construct(private readonly OpenFgaApiInterface $api) {}

    /**
     * @param list<ClientBatchCheckItem>   $checks
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
    ): ClientBatchCheckResponse {
        if ($checks === []) {
            return new ClientBatchCheckResponse([]);
        }

        $prepared = $this->prepareChecks($checks);
        $results = [];
        /** @var positive-int $batchSize */
        $batchSize = $options->maxBatchSize;
        $chunks = array_chunk($prepared, $batchSize);

        foreach ($chunks as $chunk) {
            $bulkId = Uuid::v4();
            $chunkHeaders = $headers + [
                'X-OpenFGA-Client-Bulk-Request-Id' => $bulkId,
                'X-OpenFGA-Client-Method' => 'BatchCheck',
            ];

            $body = new BatchCheckBody(
                checks: array_map(
                    static fn(array $item): BatchCheckItem => $item['model'],
                    $chunk,
                ),
                authorizationModelId: $authorizationModelId,
                consistency: $consistency,
            );

            $response = $this->api->batchCheck($storeId, $body, $chunkHeaders);
            /** @var array<string, mixed> $map */
            $map = $response->result ?? [];

            foreach ($chunk as $item) {
                $correlationId = $item['correlationId'];
                if (isset($map[$correlationId]) && is_array($map[$correlationId])) {
                    $raw = $map[$correlationId];
                } else {
                    $raw = [];
                }
                $results[] = BatchCheckSingleResult::fromArray($raw);
            }
        }

        return new ClientBatchCheckResponse($results);
    }

    /**
     * @param list<ClientBatchCheckItem> $checks
     *
     * @return list<array{correlationId: string, model: BatchCheckItem}>
     */
    private function prepareChecks(array $checks): array
    {
        $seen = [];
        $prepared = [];

        foreach ($checks as $check) {
            $correlationId = $check->correlationId ?? Uuid::v4();
            if (isset($seen[$correlationId])) {
                throw new FgaValidationException(sprintf('Duplicate correlation_id "%s" in batchCheck.', $correlationId));
            }
            $seen[$correlationId] = 1;

            $prepared[] = [
                'correlationId' => $correlationId,
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
