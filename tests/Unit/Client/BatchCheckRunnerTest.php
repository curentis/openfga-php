<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\BatchCheckRunner;
use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Http\ConcurrentSenderInterface;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;

final class BatchCheckRunnerTest extends MockTransportTestCase
{
    public function testEmptyChecksReturnsEmptyResponse(): void
    {
        $mock = new MockClient();
        $runner = new BatchCheckRunner($this->openFgaApi($mock));
        $response = $runner->run('01ARZ3NDEKTSV4RRFFQ69G5FAV', [], new BatchCheckOptions(), null, null, []);

        self::assertSame([], $response->results);
        self::assertCount(0, $mock->getRequests());
    }

    public function testCallSiteRetryOptionsAreAppliedToBatchCheck(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(500, [], '{"message":"down","code":"internal_error"}'));
        $mock->addResponse(new Response(200, [], '{"result":{}}'));
        $runner = new BatchCheckRunner($this->retryingApi($mock));

        $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            [new ClientBatchCheckItem('user:u', 'viewer', 'doc:1', correlationId: 'cid-1')],
            new BatchCheckOptions(),
            null,
            null,
            [],
            new RetryOptions(maxRetry: 1, minWaitMs: 1),
        );

        self::assertCount(2, $mock->getRequests());
    }

    public function testMissingCorrelationEntryUsesEmptyResult(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{}}'));
        $runner = new BatchCheckRunner($this->openFgaApi($mock));
        $checks = [new ClientBatchCheckItem('user:u', 'viewer', 'doc:1', correlationId: 'cid-1')];

        $response = $runner->run('01ARZ3NDEKTSV4RRFFQ69G5FAV', $checks, new BatchCheckOptions(), null, null, []);

        self::assertCount(1, $response->results);
        self::assertFalse($response->results[0]->result->allowed);
        self::assertSame('missing result for correlation_id cid-1', $response->results[0]->result->error?->message);
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

    public function testRejectsCorrelationIdsOutsideTheApiPattern(): void
    {
        $runner = new BatchCheckRunner($this->openFgaApi(new MockClient()));

        $this->expectException(FgaValidationException::class);
        $this->expectExceptionMessage('correlation_id');
        $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            [new ClientBatchCheckItem('user:u', 'viewer', 'doc:1', correlationId: 'has space')],
            new BatchCheckOptions(),
            null,
            null,
            [],
        );
    }

    public function testParallelChunksUseTheConcurrentSender(): void
    {
        $mock = new MockClient();
        $sender = new CannedParallelSender();
        $transport = $this->parallelTransport($sender);
        $runner = new BatchCheckRunner($this->openFgaApi($mock), $transport);

        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            [
                new ClientBatchCheckItem('user:u', 'viewer', 'doc:1', correlationId: 'c1'),
                new ClientBatchCheckItem('user:u', 'editor', 'doc:2', correlationId: 'c2'),
            ],
            new BatchCheckOptions(maxBatchSize: 1, maxParallelRequests: 2),
            '01HZZZZZZZZZZZZZZZZZZZZZZZ',
            null,
            ['X-Test' => '1'],
        );

        self::assertSame([2], $sender->parallelism);
        self::assertCount(0, $mock->getRequests());
        self::assertTrue($response->results[0]->result->allowed);
        self::assertTrue($response->results[1]->result->allowed);
    }

    public function testParallelTransportIsNotUsedWhenTheLimitIsOne(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{"c1":{"allowed":false}}}'));
        $sender = new CannedParallelSender();
        $runner = new BatchCheckRunner($this->openFgaApi($mock), $this->parallelTransport($sender));

        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            [new ClientBatchCheckItem('user:u', 'viewer', 'doc:1', correlationId: 'c1')],
            new BatchCheckOptions(maxParallelRequests: 1),
            null,
            null,
            [],
        );

        self::assertSame([], $sender->parallelism);
        self::assertCount(1, $mock->getRequests());
        self::assertFalse($response->results[0]->result->allowed);
    }

    public function testSequentialSenderIgnoresARaisedParallelLimit(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{"c1":{"allowed":true}}}'));
        $runner = new BatchCheckRunner($this->openFgaApi($mock), $this->transportFor($mock));

        $response = $runner->run(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            [new ClientBatchCheckItem('user:u', 'viewer', 'doc:1', correlationId: 'c1')],
            new BatchCheckOptions(maxParallelRequests: 2),
            null,
            null,
            [],
        );

        self::assertCount(1, $mock->getRequests());
        self::assertTrue($response->results[0]->result->allowed);
    }

    private function parallelTransport(ConcurrentSenderInterface $sender): \Curentis\OpenFga\Http\Transport
    {
        $factories = new \Nyholm\Psr7\Factory\Psr17Factory();
        $retry = new \Curentis\OpenFga\Http\RetryPolicy(
            0,
            100,
            new \Curentis\OpenFga\Tests\Support\FakeSleeper(),
            new \Curentis\OpenFga\Tests\Support\FrozenClock(new \DateTimeImmutable('@1700000000')),
            new \Random\Randomizer(new \Random\Engine\Mt19937(1)),
        );

        return new \Curentis\OpenFga\Http\Transport(
            'http://localhost:8080',
            [],
            new MockClient(),
            $factories,
            $factories,
            $factories,
            $retry,
            new \Curentis\OpenFga\Http\AuthorizationHeaderProvider(new \Curentis\OpenFga\Credentials\NoCredentials()),
            $sender,
        );
    }
}

/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArrayOffset
 */
final class CannedParallelSender implements ConcurrentSenderInterface
{
    /** @var list<int> */
    public array $parallelism = [];

    #[\Override]
    public function supportsParallel(): bool
    {
        return true;
    }

    #[\Override]
    public function send(array $requests, int $maxParallel): array
    {
        $this->parallelism[] = $maxParallel;
        $responses = [];
        foreach ($requests as $request) {
            $decoded = json_decode((string) $request->getBody(), true);
            $result = [];
            if (is_array($decoded) && isset($decoded['checks']) && is_array($decoded['checks'])) {
                foreach ($decoded['checks'] as $check) {
                    if (!is_array($check)) {
                        continue;
                    }
                    $id = $check['correlation_id'] ?? '';
                    if (!is_string($id) || $id === '') {
                        continue;
                    }
                    $result[$id] = ['allowed' => true];
                }
            }
            $responses[] = new Response(200, [], json_encode(['result' => $result], JSON_THROW_ON_ERROR));
        }

        return $responses;
    }
}
