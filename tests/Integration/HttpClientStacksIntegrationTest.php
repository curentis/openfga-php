<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Integration;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\OpenFgaClientInterface;
use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\ListObjectsBody;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;
use Curentis\OpenFga\Tests\Support\RequiresOpenFgaServerTrait;
use GuzzleHttp\Client as GuzzleClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;

final class HttpClientStacksIntegrationTest extends TestCase
{
    use RequiresOpenFgaServerTrait;

    public function testParallelBatchCheckThroughGuzzle(): void
    {
        $this->skipUnlessOpenFgaReachable();

        $this->withSeededStore(new GuzzleClient(), static function (OpenFgaClientInterface $fga): void {
            $batch = $fga->batchCheck(
                [
                    new ClientBatchCheckItem('user:anne', 'viewer', 'document:a', correlationId: 'a'),
                    new ClientBatchCheckItem('user:anne', 'viewer', 'document:b', correlationId: 'b'),
                    new ClientBatchCheckItem('user:anne', 'viewer', 'document:c', correlationId: 'c'),
                ],
                new BatchCheckOptions(maxBatchSize: 1, maxParallelRequests: 3),
            );

            $allowed = [];
            foreach ($batch->results as $result) {
                $allowed[$result->correlationId] = $result->result->allowed;
            }
            ksort($allowed);
            self::assertSame(['a' => true, 'b' => true, 'c' => false], $allowed);
        });
    }

    public function testStreamedListObjectsThroughSymfonyPsr18(): void
    {
        $this->skipUnlessOpenFgaReachable();

        $factories = new Psr17Factory();
        $this->withSeededStore(new Psr18Client(null, $factories, $factories), static function (OpenFgaClientInterface $fga): void {
            $objects = iterator_to_array($fga->streamedListObjects(new ListObjectsBody(
                type: 'document',
                relation: 'viewer',
                user: 'user:anne',
            )), false);
            sort($objects);

            self::assertSame(['document:a', 'document:b'], $objects);
        });
    }

    /**
     * @param callable(OpenFgaClientInterface): void $assertions
     */
    private function withSeededStore(ClientInterface $http, callable $assertions): void
    {
        $factories = new Psr17Factory();
        $fga = OpenFgaClientFactory::create(new ClientConfiguration(
            apiUrl: $this->openFgaBaseUrl(),
            httpClient: $http,
            requestFactory: $factories,
            streamFactory: $factories,
            uriFactory: $factories,
        ));
        $fga = $fga->withStoreId($fga->createStore('http-stacks-' . bin2hex(random_bytes(4)))->id);

        try {
            $modelId = $fga->writeAuthorizationModel(WriteAuthorizationModelBody::fromArray([
                'schema_version' => '1.1',
                'type_definitions' => [
                    ['type' => 'user'],
                    [
                        'type' => 'document',
                        'relations' => ['viewer' => ['this' => new \stdClass()]],
                        'metadata' => [
                            'relations' => ['viewer' => ['directly_related_user_types' => [['type' => 'user']]]],
                        ],
                    ],
                ],
            ]))->authorizationModelId;
            $fga = $fga->withAuthorizationModelId($modelId);
            $fga->write(new ClientWriteRequest(writes: [
                new ClientTupleKey('user:anne', 'viewer', 'document:a'),
                new ClientTupleKey('user:anne', 'viewer', 'document:b'),
            ]));

            $assertions($fga);
        } finally {
            $fga->deleteStore();
        }
    }
}
