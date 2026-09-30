<?php

declare(strict_types=1);

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientListRelationsRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;

require dirname(__DIR__) . '/vendor/autoload.php';

$apiUrl = getenv('FGA_API_URL');
if ($apiUrl === false || $apiUrl === '') {
    $apiUrl = 'http://localhost:8080';
}

$fga = OpenFgaClientFactory::create(new ClientConfiguration(apiUrl: $apiUrl));

$storeId = $fga->createStore('batch-check-demo')->id;
$fga = $fga->withStoreId($storeId);

$modelId = $fga->writeAuthorizationModel(WriteAuthorizationModelBody::fromArray([
    'schema_version' => '1.1',
    'type_definitions' => [
        ['type' => 'user'],
        [
            'type' => 'document',
            'relations' => [
                'viewer' => ['this' => new stdClass()],
                'editor' => ['this' => new stdClass()],
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
    new ClientTupleKey('user:anne', 'viewer', 'document:plan'),
    new ClientTupleKey('user:anne', 'editor', 'document:plan'),
]));

$batch = $fga->batchCheck([
    new ClientBatchCheckItem('user:anne', 'viewer', 'document:plan', correlationId: 'viewer'),
    new ClientBatchCheckItem('user:bob', 'viewer', 'document:plan', correlationId: 'bob-viewer'),
]);

echo 'Anne viewer: ', $batch->results[0]->allowed ? 'yes' : 'no', PHP_EOL;
echo 'Bob viewer: ', $batch->results[1]->allowed ? 'yes' : 'no', PHP_EOL;

$relations = $fga->listRelations(new ClientListRelationsRequest(
    user: 'user:anne',
    object: 'document:plan',
    relations: ['viewer', 'editor', 'owner'],
));

echo 'Anne relations on document:plan: ', implode(', ', $relations->relations), PHP_EOL;

$single = $fga->check(new ClientCheckRequest('user:anne', 'editor', 'document:plan'));
echo 'Anne editor (single check): ', $single->allowed ? 'yes' : 'no', PHP_EOL;
