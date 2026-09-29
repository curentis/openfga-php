<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\Options\TransactionOptions;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Client\WriteRunner;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;

final class WriteRunnerTest extends MockTransportTestCase
{
    public function testNonTransactionalWriteContinuesAfterFailure(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $mock->addResponse(new Response(500, [], '{"message":"fail","code":"internal_error"}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $mock->addResponse(new Response(200, [], '{}'));

        $request = new ClientWriteRequest(
            writes: [
                new ClientTupleKey('user:a', 'viewer', 'doc:1'),
                new ClientTupleKey('user:b', 'viewer', 'doc:2'),
                new ClientTupleKey('user:c', 'viewer', 'doc:3'),
            ],
            deletes: [
                new ClientTupleKeyWithoutCondition('user:d', 'viewer', 'doc:4'),
                new ClientTupleKeyWithoutCondition('user:e', 'viewer', 'doc:5'),
            ],
        );

        $runner = new WriteRunner($this->openFgaApi($mock));
        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            $request,
            null,
            new WriteOptions(
                transaction: new TransactionOptions(disable: true),
                maxPerChunk: 1,
            ),
            [],
        );

        self::assertCount(5, $response->tupleResults);
        self::assertCount(5, $mock->getRequests());
        $failures = array_filter($response->tupleResults, static fn($r): bool => !$r->success);
        self::assertCount(1, $failures);
    }
}
