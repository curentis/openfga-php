<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Integration;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Request\ClientListRelationsRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;
use Curentis\OpenFga\Tests\Support\RequiresOpenFgaServerTrait;
use PHPUnit\Framework\TestCase;

final class BatchCheckListRelationsIntegrationTest extends TestCase
{
    use RequiresOpenFgaServerTrait;

    public function testBatchCheckAndListRelations(): void
    {
        $this->skipUnlessOpenFgaReachable();

        $fga = OpenFgaClientFactory::create(new ClientConfiguration(apiUrl: $this->openFgaBaseUrl()));

        $storeId = $fga->createStore('batch-list-relations-' . bin2hex(random_bytes(4)))->id;
        $fga = $fga->withStoreId($storeId);

        try {
            $modelId = $fga->writeAuthorizationModel(WriteAuthorizationModelBody::fromArray([
                'schema_version' => '1.1',
                'type_definitions' => [
                    ['type' => 'user'],
                    [
                        'type' => 'document',
                        'relations' => [
                            'viewer' => ['this' => new \stdClass()],
                            'editor' => ['this' => new \stdClass()],
                        ],
                        'metadata' => [
                            'relations' => [
                                'viewer' => ['directly_related_user_types' => [['type' => 'user']]],
                                'editor' => ['directly_related_user_types' => [['type' => 'user']]],
                            ],
                        ],
                    ],
                ],
            ]))->authorizationModelId;
            $fga = $fga->withAuthorizationModelId($modelId);

            $fga->write(new ClientWriteRequest(writes: [
                new ClientTupleKey('user:anne', 'viewer', 'document:roadmap'),
            ]));

            $batch = $fga->batchCheck([
                new ClientBatchCheckItem('user:anne', 'viewer', 'document:roadmap', correlationId: 'v'),
                new ClientBatchCheckItem('user:anne', 'editor', 'document:roadmap', correlationId: 'e'),
            ]);

            self::assertCount(2, $batch->results);
            self::assertTrue($batch->results[0]->result->allowed);
            self::assertFalse($batch->results[1]->result->allowed);

            $relations = $fga->listRelations(new ClientListRelationsRequest(
                'user:anne',
                'document:roadmap',
                ['viewer', 'editor'],
            ));

            self::assertSame(['viewer'], $relations->relations);
        } finally {
            $fga->deleteStore();
        }
    }
}
