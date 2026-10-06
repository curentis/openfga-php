<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Integration;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\Options\ConflictOptions;
use Curentis\OpenFga\Client\Options\OnDuplicateWrites;
use Curentis\OpenFga\Client\Options\OnMissingDeletes;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Model\ReadBody;
use Curentis\OpenFga\Model\ReadRequestTupleKey;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;
use Curentis\OpenFga\Tests\Support\RequiresOpenFgaServerTrait;
use PHPUnit\Framework\TestCase;

final class WriteTuplesReadLatestIntegrationTest extends TestCase
{
    use RequiresOpenFgaServerTrait;

    public function testReadLatestWriteTuplesAndIdempotentConflictOptions(): void
    {
        $this->skipUnlessOpenFgaReachable();

        $fga = OpenFgaClientFactory::create(new ClientConfiguration(apiUrl: $this->openFgaBaseUrl()));

        $storeId = $fga->createStore('write-tuples-' . bin2hex(random_bytes(4)))->id;
        $fga = $fga->withStoreId($storeId);

        try {
            self::assertNull($fga->readLatestAuthorizationModel());

            $modelId = $fga->writeAuthorizationModel(WriteAuthorizationModelBody::fromArray([
                'schema_version' => '1.1',
                'type_definitions' => [
                    ['type' => 'user'],
                    [
                        'type' => 'document',
                        'relations' => ['viewer' => ['this' => new \stdClass()]],
                        'metadata' => [
                            'relations' => [
                                'viewer' => ['directly_related_user_types' => [['type' => 'user']]],
                            ],
                        ],
                    ],
                ],
            ]))->authorizationModelId;
            $fga = $fga->withAuthorizationModelId($modelId);

            $latest = $fga->readLatestAuthorizationModel();
            self::assertNotNull($latest);
            self::assertNotNull($latest->authorizationModel);
            self::assertSame($modelId, $latest->authorizationModel->id);

            $tuple = new ClientTupleKey('user:anne', 'viewer', 'document:plan');
            $fga->writeTuples([$tuple]);

            $fga->writeTuples(
                [$tuple],
                new WriteOptions(conflict: new ConflictOptions(onDuplicateWrites: OnDuplicateWrites::Ignore)),
            );

            self::assertTrue($fga->check(new ClientCheckRequest(
                'user:anne',
                'viewer',
                'document:plan',
            ))->allowed);

            $read = $fga->read(new ReadBody(
                tupleKey: new ReadRequestTupleKey(
                    user: 'user:anne',
                    relation: 'viewer',
                    object: 'document:plan',
                ),
            ));
            self::assertCount(1, $read->tuples);

            $fga->deleteTuples([
                new ClientTupleKeyWithoutCondition('user:anne', 'viewer', 'document:plan'),
            ], new WriteOptions(conflict: new ConflictOptions(onMissingDeletes: OnMissingDeletes::Ignore)));

            self::assertFalse($fga->check(new ClientCheckRequest(
                'user:anne',
                'viewer',
                'document:plan',
            ))->allowed);

            $readAfterDelete = $fga->read(new ReadBody(
                tupleKey: new ReadRequestTupleKey(
                    user: 'user:anne',
                    relation: 'viewer',
                    object: 'document:plan',
                ),
            ));
            self::assertSame([], $readAfterDelete->tuples);
        } finally {
            $fga->deleteStore();
        }
    }
}
