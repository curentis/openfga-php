<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;

final class OpenFgaClientReadLatestTest extends MockTransportTestCase
{
    public function testReadLatestReturnsNullWhenStoreHasNoModels(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"authorization_models":[],"continuation_token":""}'));

        $client = $this->openFgaClient($mock);
        self::assertNull($client->readLatestAuthorizationModel());
    }

    public function testReadLatestFetchesMostRecentModelById(): void
    {
        $modelId = '01JAAAAAAAAAAAAAAAAAAAAAAAAA';
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'authorization_models' => [
                ['id' => $modelId, 'schema_version' => '1.1', 'type_definitions' => []],
            ],
            'continuation_token' => '',
        ], JSON_THROW_ON_ERROR)));
        $mock->addResponse(new Response(200, [], json_encode([
            'authorization_model' => [
                'id' => $modelId,
                'schema_version' => '1.1',
                'type_definitions' => [['type' => 'user']],
            ],
        ], JSON_THROW_ON_ERROR)));

        $client = $this->openFgaClient($mock);
        $latest = $client->readLatestAuthorizationModel();

        self::assertNotNull($latest);
        self::assertNotNull($latest->authorizationModel);
        self::assertSame($modelId, $latest->authorizationModel->id);

        $requests = $mock->getRequests();
        self::assertCount(2, $requests);
        self::assertStringContainsString('page_size=1', $requests[0]->getUri()->getQuery());
        self::assertStringContainsString(
            '/authorization-models/' . $modelId,
            $requests[1]->getUri()->getPath(),
        );
    }
}
