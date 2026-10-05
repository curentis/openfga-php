<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Options\PaginationOptions;
use Curentis\OpenFga\Client\Options\RequestOptions;
use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Exception\FgaRequiredParamException;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Model\ConsistencyPreference;
use Curentis\OpenFga\Model\ExpandBody;
use Curentis\OpenFga\Model\ExpandRequestTupleKey;
use Curentis\OpenFga\Model\FgaObject;
use Curentis\OpenFga\Model\ListObjectsBody;
use Curentis\OpenFga\Model\ListUsersBody;
use Curentis\OpenFga\Model\ReadBody;
use Curentis\OpenFga\Model\TypeDefinition;
use Curentis\OpenFga\Model\UserTypeFilter;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;

final class OpenFgaClientTest extends MockTransportTestCase
{
    private const string STORE_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const string MODEL_ID = '01HZZZZZZZZZZZZZZZZZZZZZZZ';

    public function testWithStoreIdAndAuthorizationModelIdReturnNewClient(): void
    {
        $mock = new MockClient();
        $client = $this->openFgaClient($mock);
        $other = $client->withStoreId('01J00000000000000000000000')
            ->withAuthorizationModelId('01J11111111111111111111111');

        self::assertNotSame($client, $other);
    }

    public function testCreateStoreWithoutRequestOptionsSendsNoExtraHeaders(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], $this->storeJson()));
        $client = $this->openFgaClient($mock);

        $client->createStore('new-store');
        self::assertSame('', $this->lastRequest($mock)->getHeaderLine('X-H'));
    }

    public function testListStoresAndCreateStore(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"stores":[],"continuation_token":""}'));
        $mock->addResponse(new Response(200, [], $this->storeJson()));
        $client = $this->openFgaClient($mock);

        self::assertSame([], $client->listStores(new PaginationOptions(pageSize: 5), name: 'x')->stores);
        $client->createStore('new-store', new RequestOptions(headers: ['X-H' => '1']));
        $request = $this->lastRequest($mock);
        self::assertStringContainsString('"name":"new-store"', (string) $request->getBody());
        self::assertSame('1', $request->getHeaderLine('X-H'));
    }

    public function testGetStoreDeleteStoreAndReadAuthorizationModels(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], $this->storeJson()));
        $mock->addResponse(new Response(204, [], ''));
        $mock->addResponse(new Response(200, [], '{"authorization_models":[],"continuation_token":""}'));
        $client = $this->openFgaClient($mock);

        self::assertSame(self::STORE_ID, $client->getStore()->id);
        $client->deleteStore();
        self::assertSame('DELETE', $this->lastRequest($mock)->getMethod());
        self::assertSame([], $client->readAuthorizationModels()->authorizationModels);
    }

    public function testWriteAndReadAuthorizationModel(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"authorization_model_id":"' . self::MODEL_ID . '"}'));
        $mock->addResponse(new Response(200, [], json_encode([
            'authorization_model' => [
                'id' => self::MODEL_ID,
                'schema_version' => '1.1',
                'type_definitions' => [],
            ],
        ], JSON_THROW_ON_ERROR)));
        $client = $this->openFgaClient($mock);

        $written = $client->writeAuthorizationModel(new WriteAuthorizationModelBody(
            schemaVersion: '1.1',
            typeDefinitions: [new TypeDefinition(type: 'user')],
        ));
        self::assertSame(self::MODEL_ID, $written->authorizationModelId);

        $read = $client->readAuthorizationModel();
        self::assertNotNull($read->authorizationModel);
        self::assertSame(self::MODEL_ID, $read->authorizationModel->id);
    }

    public function testReadWithNullRequestUsesDefaultBody(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"continuation_token":"","tuples":[]}'));
        $client = $this->openFgaClient($mock);

        $client->read();

        self::assertSame('{}', (string) $this->lastRequest($mock)->getBody());
    }

    public function testReadMergesPaginationAndConsistency(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"continuation_token":"","tuples":[]}'));
        $client = $this->openFgaClient($mock);

        $client->read(
            new ReadBody(pageSize: 1, continuationToken: 'from-body'),
            new PaginationOptions(pageSize: 99, continuationToken: 'from-page'),
            ConsistencyPreference::HIGHER_CONSISTENCY,
        );

        $body = (string) $this->lastRequest($mock)->getBody();
        self::assertStringContainsString('"page_size":99', $body);
        self::assertStringContainsString('"continuation_token":"from-page"', $body);
        self::assertStringContainsString('"consistency":"HIGHER_CONSISTENCY"', $body);
    }

    public function testWriteTuplesDeleteTuplesReadChangesCheckBatchCheck(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $mock->addResponse(new Response(200, [], '{"changes":[],"continuation_token":""}'));
        $mock->addResponse(new Response(200, [], '{"allowed":true}'));
        $mock->addResponse(new Response(200, [], '{"allowed":true}'));
        $mock->addResponse(new Response(200, [], '{"result":{"c1":{"allowed":true}}}'));
        $client = $this->openFgaClient($mock);

        $client->write(new ClientWriteRequest(writes: [new ClientTupleKey('user:a', 'viewer', 'doc:1')]));
        $client->writeTuples([new ClientTupleKey('user:b', 'viewer', 'doc:2')]);
        $client->deleteTuples([new ClientTupleKeyWithoutCondition('user:a', 'viewer', 'doc:1')]);
        self::assertSame([], $client->readChanges(type: 'document')->changes);

        $check = $client->check(new ClientCheckRequest('user:a', 'viewer', 'doc:1'));
        self::assertTrue($check->allowed);

        $checkWithConsistency = $client->check(
            new ClientCheckRequest('user:a', 'viewer', 'doc:1'),
            ConsistencyPreference::MINIMIZE_LATENCY,
        );
        self::assertTrue($checkWithConsistency->allowed);

        $batch = $client->batchCheck([
            new ClientBatchCheckItem('user:a', 'viewer', 'doc:1', correlationId: 'c1'),
        ]);
        self::assertTrue($batch->results[0]->result->allowed);
    }

    public function testExpandWithConsistencyUsesConfigurationAuthorizationModelId(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $client = $this->openFgaClient($mock);

        $client->expand(
            new ExpandBody(tupleKey: new ExpandRequestTupleKey(object: 'doc:1', relation: 'viewer')),
            ConsistencyPreference::HIGHER_CONSISTENCY,
        );

        $body = (string) $this->lastRequest($mock)->getBody();
        self::assertStringContainsString(self::MODEL_ID, $body);
        self::assertStringContainsString('HIGHER_CONSISTENCY', $body);
    }

    public function testExpandWithoutConsistencyPassesBodyThrough(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $client = $this->openFgaClient($mock);

        $client->expand(new ExpandBody(tupleKey: new ExpandRequestTupleKey(object: 'doc:1', relation: 'viewer')));
        self::assertStringNotContainsString('consistency', (string) $this->lastRequest($mock)->getBody());
    }

    public function testExpandListObjectsListUsersWithConsistency(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $mock->addResponse(new Response(200, [], '{"objects":[]}'));
        $mock->addResponse(new Response(200, [], '{"objects":[]}'));
        $mock->addResponse(new Response(200, [], '{"users":[]}'));
        $mock->addResponse(new Response(200, [], '{"users":[]}'));
        $client = $this->openFgaClient($mock);

        $client->expand(
            new ExpandBody(tupleKey: new ExpandRequestTupleKey(object: 'doc:1', relation: 'viewer')),
            ConsistencyPreference::HIGHER_CONSISTENCY,
        );
        $expandBody = (string) $this->lastRequest($mock)->getBody();
        self::assertStringContainsString('HIGHER_CONSISTENCY', $expandBody);

        $client->listObjects(new ListObjectsBody(relation: 'viewer', type: 'document', user: 'user:u'));
        self::assertStringNotContainsString('consistency', (string) $this->lastRequest($mock)->getBody());

        $client->listObjects(
            new ListObjectsBody(relation: 'viewer', type: 'document', user: 'user:u'),
            ConsistencyPreference::HIGHER_CONSISTENCY,
        );
        self::assertStringContainsString('HIGHER_CONSISTENCY', (string) $this->lastRequest($mock)->getBody());

        $client->listUsers(new ListUsersBody(
            object: new FgaObject(id: '1', type: 'document'),
            relation: 'viewer',
            userFilters: [new UserTypeFilter(type: 'user')],
        ));
        self::assertStringNotContainsString('consistency', (string) $this->lastRequest($mock)->getBody());

        $client->listUsers(
            new ListUsersBody(
                object: new FgaObject(id: '1', type: 'document'),
                relation: 'viewer',
                userFilters: [new UserTypeFilter(type: 'user')],
            ),
            ConsistencyPreference::HIGHER_CONSISTENCY,
        );
        self::assertStringContainsString('HIGHER_CONSISTENCY', (string) $this->lastRequest($mock)->getBody());
    }

    public function testStreamedListObjectsYieldsObjectIds(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{"object":"doc:1"}}' . "\n" . '{"ignored":true}' . "\n"));
        $client = $this->openFgaClient($mock);

        $objects = iterator_to_array($client->streamedListObjects(
            new ListObjectsBody(relation: 'viewer', type: 'document', user: 'user:u'),
            ConsistencyPreference::HIGHER_CONSISTENCY,
        ));

        self::assertSame(['doc:1'], $objects);
    }

    public function testStreamedListObjectsWithoutConsistencyUsesOriginalBody(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{"object":"doc:9"}}' . "\n"));
        $client = $this->openFgaClient($mock);

        self::assertSame(['doc:9'], iterator_to_array($client->streamedListObjects(
            new ListObjectsBody(relation: 'viewer', type: 'document', user: 'user:u'),
        )));
        self::assertStringNotContainsString('consistency', (string) $this->lastRequest($mock)->getBody());
    }

    public function testStreamedListObjectsSkipsMalformedResultLines(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":"not-an-array"}' . "\n"));
        $client = $this->openFgaClient($mock);

        self::assertSame([], iterator_to_array($client->streamedListObjects(
            new ListObjectsBody(relation: 'viewer', type: 'document', user: 'user:u'),
        )));
    }

    public function testStreamedListObjectsSkipsLinesWithoutObjectKey(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{}}' . "\n" . '{"result":{"object":"doc:ok"}}' . "\n"));
        $client = $this->openFgaClient($mock);

        self::assertSame(['doc:ok'], iterator_to_array($client->streamedListObjects(
            new ListObjectsBody(relation: 'viewer', type: 'document', user: 'user:u'),
        )));
    }

    public function testStreamedListObjectsSkipsNonStringObjectValues(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{"object":123}}' . "\n" . '{"result":{"object":"doc:ok"}}' . "\n"));
        $client = $this->openFgaClient($mock);

        self::assertSame(['doc:ok'], iterator_to_array($client->streamedListObjects(
            new ListObjectsBody(relation: 'viewer', type: 'document', user: 'user:u'),
        )));
    }

    public function testReadAndWriteAssertions(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"authorization_model_id":"' . self::MODEL_ID . '","assertions":[]}'));
        $mock->addResponse(new Response(204, [], ''));
        $client = $this->openFgaClient($mock);

        self::assertSame(self::MODEL_ID, $client->readAssertions()->authorizationModelId);
        $client->writeAssertions([]);
        self::assertSame('PUT', $this->lastRequest($mock)->getMethod());
    }

    public function testExecuteApiRequestAndStreamedRequest(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"line":1}' . "\n" . '{"line":2}'));
        $mock->addResponse(new Response(200, [], '{"line":3}' . "\n" . '{"line":4}'));
        $client = $this->openFgaClient($mock);

        $response = $client->executeApiRequest(
            'GET',
            '/stores/{store_id}/custom',
            ['store_id' => self::STORE_ID],
            ['page_size' => 5, 'limit' => 10, 'nullable' => null, 'bad' => ['array']],
            null,
            new RequestOptions(headers: ['X-Req' => 'yes']),
        );
        self::assertSame(200, $response->getStatusCode());
        $request = $this->lastRequest($mock);
        self::assertStringNotContainsString('{', $request->getUri()->getPath());
        self::assertStringContainsString(self::STORE_ID, $request->getUri()->getPath());
        $query = $request->getUri()->getQuery();
        parse_str($query, $parsed);
        self::assertSame('5', $parsed['page_size'] ?? null);
        self::assertSame('10', $parsed['limit'] ?? null);
        self::assertArrayNotHasKey('nullable', $parsed);
        self::assertSame('array', $parsed['bad'] ?? null);
        self::assertCount(3, $parsed);

        $lines = iterator_to_array($client->executeStreamedApiRequest(
            'GET',
            '/stores/{store_id}/custom',
            ['store_id' => self::STORE_ID],
        ));
        self::assertCount(2, $lines);
        self::assertSame(3, $lines[0]['line']);
    }

    public function testExecuteApiRequestValidatesPathParameters(): void
    {
        $mock = new MockClient();
        $client = $this->openFgaClient($mock);

        $this->expectException(FgaValidationException::class);
        $client->executeApiRequest('GET', '/stores/{store_id}/custom', []);
    }

    public function testReadAuthorizationModelUsesRequestOptionsAuthorizationModelId(): void
    {
        $overrideModelId = '01JBBBBBBBBBBBBBBBBBBBBBBB';
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'authorization_model' => [
                'id' => $overrideModelId,
                'schema_version' => '1.1',
                'type_definitions' => [],
            ],
        ], JSON_THROW_ON_ERROR)));
        $client = $this->openFgaClient($mock);

        $client->readAuthorizationModel(new RequestOptions(authorizationModelId: $overrideModelId));

        self::assertStringContainsString(
            '/authorization-models/' . $overrideModelId,
            $this->lastRequest($mock)->getUri()->getPath(),
        );
    }

    public function testBatchCheckWithNullOptionsUsesDefaults(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{"c1":{"allowed":true}}}'));
        $client = $this->openFgaClient($mock);

        $batch = $client->batchCheck([
            new ClientBatchCheckItem('user:a', 'viewer', 'doc:1', correlationId: 'c1'),
        ], null);

        self::assertTrue($batch->results[0]->result->allowed);
        self::assertCount(1, $mock->getRequests());
    }

    public function testListRelationsWithNullBatchOptionsUsesDefaults(): void
    {
        $mock = new MockClient();
        $http = new RelationBatchClient(['viewer' => true]);
        $client = $this->openFgaClientFromHttp($http);

        $response = $client->listRelations(
            new \Curentis\OpenFga\Client\Request\ClientListRelationsRequest('user:a', 'doc:1', ['viewer']),
            null,
        );

        self::assertSame(['viewer'], $response->relations);
    }

    public function testWriteWithNullWriteOptionsUsesTransactionalWrite(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $client = $this->openFgaClient($mock);

        $client->write(new ClientWriteRequest(writes: [new ClientTupleKey('user:a', 'viewer', 'doc:1')]), null);

        self::assertSame('Write', $this->lastRequest($mock)->getHeaderLine('X-OpenFGA-Client-Method'));
    }

    public function testWriteWithExplicitWriteOptionsUsesTransactionalWrite(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $client = $this->openFgaClient($mock);

        $client->write(
            new ClientWriteRequest(writes: [new ClientTupleKey('user:a', 'viewer', 'doc:1')]),
            new WriteOptions(),
        );

        self::assertSame('Write', $this->lastRequest($mock)->getHeaderLine('X-OpenFGA-Client-Method'));
    }

    public function testBatchCheckWithExplicitBatchOptions(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{"c1":{"allowed":true}}}'));
        $client = $this->openFgaClient($mock);

        $batch = $client->batchCheck(
            [new ClientBatchCheckItem('user:a', 'viewer', 'doc:1', correlationId: 'c1')],
            new BatchCheckOptions(maxBatchSize: 10),
        );

        self::assertTrue($batch->results[0]->result->allowed);
    }

    public function testNullStoreIdInRequestOptionsFallsBackToConfiguration(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], $this->storeJson()));
        $client = $this->openFgaClientWithConfiguration($mock, new ClientConfiguration(storeId: self::STORE_ID));

        $client->getStore(new RequestOptions(storeId: null));
        self::assertSame('/stores/' . self::STORE_ID, $this->lastRequest($mock)->getUri()->getPath());
    }

    public function testRequestOptionsOverrideStoreAndModel(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], $this->storeJson()));
        $client = $this->openFgaClientWithConfiguration($mock, new ClientConfiguration(
            storeId: null,
            authorizationModelId: null,
        ));

        $client->getStore(new RequestOptions(
            storeId: self::STORE_ID,
            authorizationModelId: self::MODEL_ID,
        ));
        self::assertSame('/stores/' . self::STORE_ID, $this->lastRequest($mock)->getUri()->getPath());
    }

    public function testRequireStoreIdThrowsWhenOptionsProvideEmptyString(): void
    {
        $mock = new MockClient();
        $client = $this->openFgaClientWithConfiguration($mock, new ClientConfiguration(storeId: null));

        $this->expectException(FgaRequiredParamException::class);
        $client->getStore(new RequestOptions(storeId: ''));
    }

    public function testRequireStoreIdThrowsWhenMissing(): void
    {
        $mock = new MockClient();
        $client = $this->openFgaClientWithConfiguration($mock, new ClientConfiguration(storeId: null));

        $this->expectException(FgaRequiredParamException::class);
        $this->expectExceptionMessage('storeId');
        $client->getStore();
    }

    public function testRequireAuthorizationModelIdThrowsWhenOptionsProvideEmptyString(): void
    {
        $mock = new MockClient();
        $client = $this->openFgaClientWithConfiguration($mock, new ClientConfiguration(
            storeId: self::STORE_ID,
            authorizationModelId: null,
        ));

        $this->expectException(FgaRequiredParamException::class);
        $client->readAuthorizationModel(new RequestOptions(authorizationModelId: ''));
    }

    public function testRequireAuthorizationModelIdThrowsWhenMissing(): void
    {
        $mock = new MockClient();
        $client = $this->openFgaClientWithConfiguration($mock, new ClientConfiguration(
            storeId: self::STORE_ID,
            authorizationModelId: null,
        ));

        $this->expectException(FgaRequiredParamException::class);
        $this->expectExceptionMessage('authorizationModelId');
        $client->readAuthorizationModel();
    }

    public function testExecuteApiRequestRejectsNonScalarParameters(): void
    {
        $client = $this->openFgaClient(new MockClient());

        try {
            $client->executeApiRequest('GET', '/stores/{store_id}', ['store_id' => ['nested']]);
            self::fail('Expected a path parameter error');
        } catch (FgaValidationException $exception) {
            self::assertStringContainsString('scalar', $exception->getMessage());
        }

        try {
            $client->executeApiRequest('GET', '/stores', [], ['bad' => [new \stdClass()]]);
            self::fail('Expected a query parameter error');
        } catch (FgaValidationException $exception) {
            self::assertStringContainsString('list of scalars', $exception->getMessage());
        }

        try {
            $client->executeApiRequest('GET', '/stores', [], ['bad' => new \stdClass()]);
            self::fail('Expected a scalar query parameter error');
        } catch (FgaValidationException $exception) {
            self::assertStringContainsString('must be a scalar', $exception->getMessage());
        }

        $this->expectException(FgaValidationException::class);
        $this->expectExceptionMessage('Parameter names must be strings');
        $client->executeApiRequest('GET', '/stores', [0 => 'skip']);
    }

    public function testExecuteApiRequestRejectsNonStringQueryNames(): void
    {
        $client = $this->openFgaClient(new MockClient());

        $this->expectException(FgaValidationException::class);
        $this->expectExceptionMessage('Parameter names must be strings');
        $client->executeApiRequest('GET', '/stores', [], [0 => 'skip']);
    }

    public function testRequestOptionsRetryIsApplied(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"stores":[],"continuation_token":""}'));
        $client = $this->openFgaClient($mock);

        self::assertSame([], $client->listStores(options: new RequestOptions(retry: new RetryOptions(maxRetry: 0)))->stores);
    }

    public function testRequestOptionsRejectInvalidUlids(): void
    {
        $this->expectException(FgaValidationException::class);
        new RequestOptions(storeId: 'not-a-ulid');
    }

    private function storeJson(): string
    {
        return json_encode([
            'id' => self::STORE_ID,
            'name' => 'store',
            'created_at' => '2024-01-01T00:00:00Z',
            'updated_at' => '2024-01-01T00:00:00Z',
        ], JSON_THROW_ON_ERROR);
    }
}
