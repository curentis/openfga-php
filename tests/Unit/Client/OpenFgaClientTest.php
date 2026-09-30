<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\Options\PaginationOptions;
use Curentis\OpenFga\Client\Options\RequestOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Exception\FgaRequiredParamException;
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
        $mock->addResponse(new Response(200, [], '{"result":{"c1":{"allowed":true}}}'));
        $client = $this->openFgaClient($mock);

        $client->write(new ClientWriteRequest(writes: [new ClientTupleKey('user:a', 'viewer', 'doc:1')]));
        $client->writeTuples([new ClientTupleKey('user:b', 'viewer', 'doc:2')]);
        $client->deleteTuples([new ClientTupleKeyWithoutCondition('user:a', 'viewer', 'doc:1')]);
        self::assertSame([], $client->readChanges(type: 'document')->changes);

        $check = $client->check(new ClientCheckRequest('user:a', 'viewer', 'doc:1'), ConsistencyPreference::MINIMIZE_LATENCY);
        self::assertTrue($check->allowed);

        $batch = $client->batchCheck([
            new ClientBatchCheckItem('user:a', 'viewer', 'doc:1', correlationId: 'c1'),
        ]);
        self::assertTrue($batch->results[0]->allowed);
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
            ['store_id' => self::STORE_ID, 0 => 'skip'],
            ['page_size' => 5, 'bad' => ['array']],
            null,
            new RequestOptions(headers: ['X-Req' => 'yes']),
        );
        self::assertSame(200, $response->getStatusCode());

        $lines = iterator_to_array($client->executeStreamedApiRequest(
            'GET',
            '/stores/{store_id}/custom',
            ['store_id' => self::STORE_ID],
        ));
        self::assertCount(2, $lines);
        self::assertSame(3, $lines[0]['line']);
    }

    public function testRequestOptionsOverrideStoreAndModel(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], $this->storeJson()));
        $config = new ClientConfiguration(
            storeId: null,
            authorizationModelId: null,
        );
        $transport = $this->transport($mock);
        $client = new \Curentis\OpenFga\Client\OpenFgaClient(
            $config,
            new \Curentis\OpenFga\Api\OpenFgaApi($transport),
            $transport,
        );

        $client->getStore(new RequestOptions(
            storeId: self::STORE_ID,
            authorizationModelId: self::MODEL_ID,
        ));
        self::assertSame('/stores/' . self::STORE_ID, $this->lastRequest($mock)->getUri()->getPath());
    }

    public function testRequireStoreIdThrowsWhenOptionsProvideEmptyString(): void
    {
        $mock = new MockClient();
        $config = new ClientConfiguration(storeId: null);
        $client = new \Curentis\OpenFga\Client\OpenFgaClient(
            $config,
            $this->openFgaApi($mock),
            $this->transport($mock),
        );

        $this->expectException(FgaRequiredParamException::class);
        $client->getStore(new RequestOptions(storeId: ''));
    }

    public function testRequireStoreIdThrowsWhenMissing(): void
    {
        $mock = new MockClient();
        $config = new ClientConfiguration(storeId: null);
        $client = new \Curentis\OpenFga\Client\OpenFgaClient(
            $config,
            $this->openFgaApi($mock),
            $this->transport($mock),
        );

        $this->expectException(FgaRequiredParamException::class);
        $this->expectExceptionMessage('storeId');
        $client->getStore();
    }

    public function testRequireAuthorizationModelIdThrowsWhenMissing(): void
    {
        $mock = new MockClient();
        $config = new ClientConfiguration(
            storeId: self::STORE_ID,
            authorizationModelId: null,
        );
        $client = new \Curentis\OpenFga\Client\OpenFgaClient(
            $config,
            $this->openFgaApi($mock),
            $this->transport($mock),
        );

        $this->expectException(FgaRequiredParamException::class);
        $this->expectExceptionMessage('authorizationModelId');
        $client->readAuthorizationModel();
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
