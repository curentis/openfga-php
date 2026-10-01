<?php

declare(strict_types=1);

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;

require dirname(__DIR__) . '/vendor/autoload.php';

$fga = OpenFgaClientFactory::create(new ClientConfiguration(
    apiUrl: getenv('FGA_API_URL') !== false && getenv('FGA_API_URL') !== '' ? getenv('FGA_API_URL') : 'http://localhost:8080',
));

$storeId = $fga->createStore('quickstart')->id;
$fga = $fga->withStoreId($storeId);

$modelId = $fga->writeAuthorizationModel(WriteAuthorizationModelBody::fromArray([
    'schema_version' => '1.1',
    'type_definitions' => [
        ['type' => 'user'],
        [
            'type' => 'document',
            'relations' => ['viewer' => ['this' => new stdClass()]],
            'metadata' => [
                'relations' => [
                    'viewer' => ['directly_related_user_types' => [['type' => 'user']]],
                ],
            ],
        ],
    ],
]))->authorizationModelId;
$fga = $fga->withAuthorizationModelId($modelId);

$fga->write(new ClientWriteRequest(writes: [new ClientTupleKey('user:anne', 'viewer', 'document:roadmap')]));

var_dump($fga->check(new ClientCheckRequest('user:anne', 'viewer', 'document:roadmap'))->allowed);
