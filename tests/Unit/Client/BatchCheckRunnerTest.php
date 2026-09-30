<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\BatchCheckRunner;
use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;

final class BatchCheckRunnerTest extends MockTransportTestCase
{
    public function testEmptyChecksReturnsEmptyResponse(): void
    {
        $runner = new BatchCheckRunner($this->openFgaApi(new MockClient()));
        $response = $runner->run('01ARZ3NDEKTSV4RRFFQ69G5FAV', [], new BatchCheckOptions(), null, null, []);

        self::assertSame([], $response->results);
    }

    public function testMissingCorrelationEntryUsesEmptyResult(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{}}'));
        $runner = new BatchCheckRunner($this->openFgaApi($mock));
        $checks = [new ClientBatchCheckItem('user:u', 'viewer', 'doc:1', correlationId: 'cid-1')];

        $response = $runner->run('01ARZ3NDEKTSV4RRFFQ69G5FAV', $checks, new BatchCheckOptions(), null, null, []);

        self::assertCount(1, $response->results);
        self::assertNull($response->results[0]->allowed);
    }

    public function testChunksLargeBatchAndPreservesOrder(): void
    {
        $mock = new MockClient();
        for ($i = 0; $i < 3; ++$i) {
            $mock->addResponse(new Response(200, [], '{"result":{}}'));
        }

        $api = $this->openFgaApi($mock);
        $checks = [];
        for ($i = 0; $i < 120; ++$i) {
            $checks[] = new ClientBatchCheckItem(
                user: 'user:u',
                relation: 'viewer',
                object: 'doc:' . $i,
            );
        }

        $runner = new BatchCheckRunner($api);
        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            $checks,
            new BatchCheckOptions(maxBatchSize: 50),
            null,
            null,
            [],
        );

        self::assertCount(120, $response->results);
        self::assertCount(3, $mock->getRequests());

        foreach ($mock->getRequests() as $request) {
            self::assertSame('BatchCheck', $request->getHeaderLine('X-OpenFGA-Client-Method'));
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $request->getHeaderLine('X-OpenFGA-Client-Bulk-Request-Id'),
            );
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertArrayHasKey('checks', $body);
            self::assertIsArray($body['checks']);
            self::assertLessThanOrEqual(50, count($body['checks']));
        }
    }

    public function testDuplicateCorrelationIdThrows(): void
    {
        $mock = new MockClient();
        $runner = new BatchCheckRunner($this->openFgaApi($mock));
        $checks = [
            new ClientBatchCheckItem('user:u', 'viewer', 'doc:1', correlationId: 'dup'),
            new ClientBatchCheckItem('user:u', 'editor', 'doc:1', correlationId: 'dup'),
        ];

        $this->expectException(FgaValidationException::class);
        $this->expectExceptionMessage('Duplicate correlation_id');

        $runner->run('01ARZ3NDEKTSV4RRFFQ69G5FAV', $checks, new BatchCheckOptions(), null, null, []);
    }
}
