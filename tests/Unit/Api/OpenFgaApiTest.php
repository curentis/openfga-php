<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Api;

use Curentis\OpenFga\Model\BatchCheckBody;
use Curentis\OpenFga\Model\BatchCheckItem;
use Curentis\OpenFga\Model\CheckBody;
use Curentis\OpenFga\Model\CheckRequestTupleKey;
use Curentis\OpenFga\Model\CreateStoreRequest;
use Curentis\OpenFga\Model\ExpandBody;
use Curentis\OpenFga\Model\ExpandRequestTupleKey;
use Curentis\OpenFga\Model\FgaObject;
use Curentis\OpenFga\Model\ListObjectsBody;
use Curentis\OpenFga\Model\ListUsersBody;
use Curentis\OpenFga\Model\ReadBody;
use Curentis\OpenFga\Model\TypeDefinition;
use Curentis\OpenFga\Model\UserTypeFilter;
use Curentis\OpenFga\Model\WriteAssertionsBody;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;
use Curentis\OpenFga\Model\WriteBody;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;

final class OpenFgaApiTest extends MockTransportTestCase
{
    private const string STORE_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const string MODEL_ID = '01HZZZZZZZZZZZZZZZZZZZZZZZ';

    public function testListStoresOmitsNullAndEmptyQueryParameters(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"stores":[],"continuation_token":""}'));
        $api = $this->openFgaApi($mock);

        $response = $api->listStores(pageSize: 10, continuationToken: 'next', name: '', headers: ['X-Test' => '1']);

        self::assertSame([], $response->stores);
        $request = $this->lastRequest($mock);
        $query = $request->getUri()->getQuery();
        self::assertStringContainsString('page_size=10', $query);
        self::assertStringContainsString('continuation_token=next', $query);
        self::assertStringNotContainsString('name=', $query);
        self::assertSame('1', $request->getHeaderLine('X-Test'));
    }

    public function testListStoresWithoutPaginationQuery(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"stores":[],"continuation_token":""}'));
        $api = $this->openFgaApi($mock);

        $api->listStores();

        $request = $this->lastRequest($mock);
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/stores', $request->getUri()->getPath());
    }

    public function testCreateStorePostsJsonBody(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], $this->storeJson()));
        $api = $this->openFgaApi($mock);

        $response = $api->createStore(new CreateStoreRequest(name: 'demo'));

        self::assertSame(self::STORE_ID, $response->id);
        $body = (string) $this->lastRequest($mock)->getBody();
        self::assertStringContainsString('"name":"demo"', $body);
    }

    public function testGetStoreUsesPathParameter(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], $this->storeJson()));
        $api = $this->openFgaApi($mock);

        $response = $api->getStore(self::STORE_ID);

        self::assertSame(self::STORE_ID, $response->id);
        self::assertSame('/stores/' . self::STORE_ID, $this->lastRequest($mock)->getUri()->getPath());
    }

    public function testDeleteStoreSendsDelete(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(204, [], ''));
        $api = $this->openFgaApi($mock);

        $api->deleteStore(self::STORE_ID);

        $request = $this->lastRequest($mock);
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/stores/' . self::STORE_ID, $request->getUri()->getPath());
    }

    public function testReadAuthorizationModelsWithQuery(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"authorization_models":[],"continuation_token":""}'));
        $api = $this->openFgaApi($mock);

        $api->readAuthorizationModels(self::STORE_ID, pageSize: 2, continuationToken: 'tok');

        $query = $this->lastRequest($mock)->getUri()->getQuery();
        self::assertStringContainsString('page_size=2', $query);
        self::assertStringContainsString('continuation_token=tok', $query);
    }

    public function testReadChangesOmitsEmptyContinuationToken(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"changes":[],"continuation_token":""}'));
        $api = $this->openFgaApi($mock);

        $api->readChanges(self::STORE_ID, continuationToken: '');

        $query = $this->lastRequest($mock)->getUri()->getQuery();
        self::assertStringNotContainsString('continuation_token', $query);
    }

    public function testWriteAuthorizationModel(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"authorization_model_id":"' . self::MODEL_ID . '"}'));
        $api = $this->openFgaApi($mock);

        $response = $api->writeAuthorizationModel(
            self::STORE_ID,
            new WriteAuthorizationModelBody(
                schemaVersion: '1.1',
                typeDefinitions: [new TypeDefinition(type: 'user')],
            ),
        );

        self::assertSame(self::MODEL_ID, $response->authorizationModelId);
    }

    public function testReadAuthorizationModel(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'authorization_model' => [
                'id' => self::MODEL_ID,
                'schema_version' => '1.1',
                'type_definitions' => [],
            ],
        ], JSON_THROW_ON_ERROR)));
        $api = $this->openFgaApi($mock);

        $response = $api->readAuthorizationModel(self::STORE_ID, self::MODEL_ID);

        self::assertNotNull($response->authorizationModel);
        self::assertSame(self::MODEL_ID, $response->authorizationModel->id);
    }

    public function testReadWriteAndReadChanges(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"continuation_token":"","tuples":[]}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $mock->addResponse(new Response(200, [], '{"changes":[],"continuation_token":""}'));
        $api = $this->openFgaApi($mock);

        $read = $api->read(self::STORE_ID, new ReadBody(pageSize: 5));
        self::assertSame([], $read->tuples);

        $api->write(self::STORE_ID, new WriteBody());
        self::assertSame('POST', $this->lastRequest($mock)->getMethod());

        $changes = $api->readChanges(
            self::STORE_ID,
            type: 'document',
            pageSize: 25,
            continuationToken: 'c',
            startTime: '2024-01-01T00:00:00Z',
        );
        self::assertSame([], $changes->changes);
        $query = $this->lastRequest($mock)->getUri()->getQuery();
        self::assertStringContainsString('type=document', $query);
        self::assertStringContainsString('start_time=', $query);
    }

    public function testCheckBatchCheckExpandListObjectsListUsers(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"allowed":true}'));
        $mock->addResponse(new Response(200, [], '{"result":{}}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $mock->addResponse(new Response(200, [], '{"objects":[]}'));
        $mock->addResponse(new Response(200, [], '{"users":[]}'));
        $api = $this->openFgaApi($mock);

        $check = $api->check(self::STORE_ID, new CheckBody(
            tupleKey: new CheckRequestTupleKey(user: 'user:u', relation: 'viewer', object: 'doc:1'),
        ));
        self::assertTrue($check->allowed);

        $batch = $api->batchCheck(self::STORE_ID, new BatchCheckBody(checks: [
            new BatchCheckItem(
                correlationId: 'c1',
                tupleKey: new CheckRequestTupleKey(user: 'user:u', relation: 'viewer', object: 'doc:1'),
            ),
        ]));
        self::assertSame([], $batch->result);

        $api->expand(self::STORE_ID, new ExpandBody(
            tupleKey: new ExpandRequestTupleKey(object: 'doc:1', relation: 'viewer'),
        ));
        self::assertStringEndsWith('/expand', $this->lastRequest($mock)->getUri()->getPath());

        $objects = $api->listObjects(self::STORE_ID, new ListObjectsBody(relation: 'viewer', type: 'document', user: 'user:u'));
        self::assertSame([], $objects->objects);

        $users = $api->listUsers(self::STORE_ID, new ListUsersBody(
            object: new FgaObject(id: '1', type: 'document'),
            relation: 'viewer',
            userFilters: [new UserTypeFilter(type: 'user')],
        ));
        self::assertSame([], $users->users);
    }

    public function testStreamedListObjectsReturnsRawResponse(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"result":{"object":"doc:1"}}' . "\n"));
        $api = $this->openFgaApi($mock);

        $response = $api->streamedListObjects(self::STORE_ID, new ListObjectsBody(relation: 'viewer', type: 'document', user: 'user:u'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('streamed-list-objects', $this->lastRequest($mock)->getUri()->getPath());
    }

    public function testReadAndWriteAssertions(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"authorization_model_id":"' . self::MODEL_ID . '","assertions":[]}'));
        $mock->addResponse(new Response(204, [], ''));
        $api = $this->openFgaApi($mock);

        $read = $api->readAssertions(self::STORE_ID, self::MODEL_ID);
        self::assertSame(self::MODEL_ID, $read->authorizationModelId);

        $api->writeAssertions(self::STORE_ID, self::MODEL_ID, new WriteAssertionsBody(assertions: []));
        self::assertSame('PUT', $this->lastRequest($mock)->getMethod());
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
