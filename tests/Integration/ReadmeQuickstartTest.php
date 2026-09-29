<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Integration;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;
use PHPUnit\Framework\TestCase;

final class ReadmeQuickstartTest extends TestCase
{
    public function testQuickstartFlow(): void
    {
        $configuredUrl = getenv('FGA_API_URL');
        $baseUrl = rtrim(
            $configuredUrl !== false && $configuredUrl !== '' ? $configuredUrl : 'http://localhost:8080',
            '/',
        );
        $requireFlag = getenv('FGA_REQUIRE_SERVER');
        $require = ($requireFlag !== false ? $requireFlag : '0') === '1';

        $context = stream_context_create(['http' => ['timeout' => 2]]);
        if (@file_get_contents($baseUrl . '/healthz', false, $context) === false) {
            if ($require) {
                self::fail(sprintf('OpenFGA server required at %s but /healthz is unreachable.', $baseUrl));
            }

            self::markTestSkipped(sprintf('OpenFGA not running at %s (start with docker compose).', $baseUrl));
        }

        $fga = OpenFgaClientFactory::create(new ClientConfiguration(apiUrl: $baseUrl));

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
