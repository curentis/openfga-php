<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\Request\ClientListRelationsRequest;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;

final class OpenFgaClientListRelationsTest extends MockTransportTestCase
{
    public function testListRelationsMapsBatchCheckResultsByRelationName(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'result' => [
                'viewer' => ['allowed' => true],
                'editor' => ['allowed' => false],
                'owner' => ['allowed' => true],
            ],
        ], JSON_THROW_ON_ERROR)));

        $client = $this->openFgaClient($mock);
        $response = $client->listRelations(new ClientListRelationsRequest(
            'user:anne',
            'document:roadmap',
            ['viewer', 'editor', 'owner'],
        ));

        self::assertSame(['viewer', 'owner'], $response->relations);
        self::assertCount(1, $mock->getRequests());
    }

    public function testListRelationsIgnoresMissingOrDeniedResults(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'result' => [
                'viewer' => ['allowed' => false],
            ],
        ], JSON_THROW_ON_ERROR)));

        $client = $this->openFgaClient($mock);
        $response = $client->listRelations(new ClientListRelationsRequest(
            'user:anne',
            'document:roadmap',
            ['viewer', 'editor'],
        ));

        self::assertSame([], $response->relations);
    }
}
