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
    public function testTransactionalWriteUsesSingleApiCall(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"writes":[]}'));
        $request = new ClientWriteRequest(writes: [new ClientTupleKey('user:a', 'viewer', 'doc:1')]);
        $runner = new WriteRunner($this->openFgaApi($mock));

        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            $request,
            null,
            new WriteOptions(),
            [],
        );

        self::assertCount(1, $mock->getRequests());
        self::assertSame('Write', $mock->getRequests()[0]->getHeaderLine('X-OpenFGA-Client-Method'));
        self::assertSame([], $response->tupleResults);
        self::assertNotNull($response->response);
    }

    public function testNonTransactionalChunkWithWritesAndDeletesRecordsAllTupleResults(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $runner = new WriteRunner($this->openFgaApi($mock));

        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            new ClientWriteRequest(
                writes: [new ClientTupleKey('user:w', 'viewer', 'doc:1')],
                deletes: [new ClientTupleKeyWithoutCondition('user:d', 'viewer', 'doc:2')],
            ),
            null,
            new WriteOptions(
                transaction: new TransactionOptions(disable: true),
                maxPerChunk: 10,
            ),
            ['X-Custom' => '1'],
        );

        self::assertCount(2, $mock->getRequests());
        self::assertCount(2, $response->tupleResults);
        self::assertTrue($response->tupleResults[0]->success);
        self::assertTrue($response->tupleResults[1]->success);
        self::assertSame('write', $response->tupleResults[0]->operation);
        self::assertSame('delete', $response->tupleResults[1]->operation);
        self::assertSame('1', $mock->getRequests()[0]->getHeaderLine('X-Custom'));
        self::assertSame('1', $mock->getRequests()[1]->getHeaderLine('X-Custom'));
        self::assertSame('Write', $mock->getRequests()[0]->getHeaderLine('X-OpenFGA-Client-Method'));
        self::assertSame('Write', $mock->getRequests()[1]->getHeaderLine('X-OpenFGA-Client-Method'));
    }

    public function testNonTransactionalFailureChunkWithWritesAndDeletesRecordsAllFailures(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(500, [], '{"message":"fail","code":"internal_error"}'));
        $mock->addResponse(new Response(500, [], '{"message":"fail","code":"internal_error"}'));
        $runner = new WriteRunner($this->openFgaApi($mock));

        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            new ClientWriteRequest(
                writes: [new ClientTupleKey('user:w', 'viewer', 'doc:1')],
                deletes: [new ClientTupleKeyWithoutCondition('user:d', 'viewer', 'doc:2')],
            ),
            null,
            new WriteOptions(
                transaction: new TransactionOptions(disable: true),
                maxPerChunk: 10,
            ),
            [],
        );

        self::assertCount(2, $mock->getRequests());
        self::assertCount(2, $response->tupleResults);
        self::assertFalse($response->tupleResults[0]->success);
        self::assertFalse($response->tupleResults[1]->success);
    }

    public function testEmptyWriteRequestStillPerformsTransactionalCall(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $runner = new WriteRunner($this->openFgaApi($mock));

        $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            new ClientWriteRequest(),
            null,
            new WriteOptions(transaction: new TransactionOptions(disable: true)),
            [],
        );

        self::assertCount(1, $mock->getRequests());
    }

    public function testNonTransactionalDeleteFailureRecordsTupleError(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(500, [], '{"message":"fail","code":"internal_error"}'));
        $runner = new WriteRunner($this->openFgaApi($mock));

        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            new ClientWriteRequest(deletes: [new ClientTupleKeyWithoutCondition('user:d', 'viewer', 'doc:4')]),
            null,
            new WriteOptions(transaction: new TransactionOptions(disable: true)),
            [],
        );

        self::assertFalse($response->tupleResults[0]->success);
        self::assertSame('delete', $response->tupleResults[0]->operation);
    }

    public function testNonTransactionalChunkWithTwoWritesRecordsBothTupleResults(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $runner = new WriteRunner($this->openFgaApi($mock));

        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            new ClientWriteRequest(
                writes: [
                    new ClientTupleKey('user:a', 'viewer', 'doc:1'),
                    new ClientTupleKey('user:b', 'viewer', 'doc:2'),
                ],
            ),
            null,
            new WriteOptions(transaction: new TransactionOptions(disable: true), maxPerChunk: 10),
            [],
        );

        self::assertCount(2, $response->tupleResults);
        self::assertTrue($response->tupleResults[0]->success);
        self::assertTrue($response->tupleResults[1]->success);
    }

    public function testNonTransactionalChunkWithTwoDeletesRecordsBothFailures(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(500, [], '{"message":"fail","code":"internal_error"}'));
        $runner = new WriteRunner($this->openFgaApi($mock));

        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            new ClientWriteRequest(
                deletes: [
                    new ClientTupleKeyWithoutCondition('user:a', 'viewer', 'doc:1'),
                    new ClientTupleKeyWithoutCondition('user:b', 'viewer', 'doc:2'),
                ],
            ),
            null,
            new WriteOptions(transaction: new TransactionOptions(disable: true), maxPerChunk: 10),
            [],
        );

        self::assertCount(2, $response->tupleResults);
        self::assertFalse($response->tupleResults[0]->success);
        self::assertFalse($response->tupleResults[1]->success);
    }

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
