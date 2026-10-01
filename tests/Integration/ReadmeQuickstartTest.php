<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Integration;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;
use Curentis\OpenFga\Tests\Support\RequiresOpenFgaServerTrait;
use PHPUnit\Framework\TestCase;

final class ReadmeQuickstartTest extends TestCase
{
    use RequiresOpenFgaServerTrait;

    public function testQuickstartFlow(): void
    {
        $this->skipUnlessOpenFgaReachable();

        $fga = OpenFgaClientFactory::create(new ClientConfiguration(apiUrl: $this->openFgaBaseUrl()));

        $storeId = $fga->createStore('quickstart-php-' . bin2hex(random_bytes(4)))->id;
        $fga = $fga->withStoreId($storeId);

        try {
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

            $fga->write(new ClientWriteRequest(writes: [
                new ClientTupleKey('user:anne', 'viewer', 'document:roadmap'),
            ]));

            self::assertTrue(
                $fga->check(new ClientCheckRequest('user:anne', 'viewer', 'document:roadmap'))->allowed,
            );
        } finally {
            $fga->deleteStore();
        }
    }
}
