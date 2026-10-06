<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\OpenFgaClientInterface;
use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Options\TransactionOptions;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientListRelationsRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Exception\FgaException;
use Curentis\OpenFga\Model\ExpandBody;
use Curentis\OpenFga\Model\ExpandRequestTupleKey;
use Curentis\OpenFga\Model\FgaObject;
use Curentis\OpenFga\Model\ListObjectsBody;
use Curentis\OpenFga\Model\ListUsersBody;
use Curentis\OpenFga\Model\ReadBody;
use Curentis\OpenFga\Model\UserTypeFilter;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Curentis\OpenFga\Tests\Support\TestClientException;
use Curentis\OpenFga\Tests\Support\TestNetworkException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Every public client method must surface failures as `FgaException`, whatever the input or the HTTP layer does.
 */
final class NoForeignExceptionTest extends MockTransportTestCase
{
    private const string MALFORMED_UTF8 = "user:\xB1";

    /**
     * @return iterable<string, array{\Closure(OpenFgaClientInterface, string): mixed}>
     */
    public static function operations(): iterable
    {
        yield 'listStores' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->listStores(name: $text)];
        yield 'createStore' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->createStore($text)];
        yield 'getStore' => [static fn(OpenFgaClientInterface $fga): mixed => $fga->getStore()];
        yield 'deleteStore' => [static function (OpenFgaClientInterface $fga): mixed {
            $fga->deleteStore();

            return null;
        }];
        yield 'readAuthorizationModels' => [static fn(OpenFgaClientInterface $fga): mixed => $fga->readAuthorizationModels()];
        yield 'writeAuthorizationModel' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->writeAuthorizationModel(
            WriteAuthorizationModelBody::fromArray(['schema_version' => '1.1', 'type_definitions' => [['type' => $text]]]),
        )];
        yield 'readAuthorizationModel' => [static fn(OpenFgaClientInterface $fga): mixed => $fga->readAuthorizationModel()];
        yield 'readLatestAuthorizationModel' => [static fn(OpenFgaClientInterface $fga): mixed => $fga->readLatestAuthorizationModel()];
        yield 'read' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->read(new ReadBody(continuationToken: $text))];
        yield 'write' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->write(new ClientWriteRequest(
            writes: [new ClientTupleKey($text, 'viewer', 'doc:1')],
        ))];
        yield 'non-transactional write' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->write(
            new ClientWriteRequest(
                writes: [new ClientTupleKey($text, 'viewer', 'doc:1')],
                deletes: [new ClientTupleKeyWithoutCondition($text, 'viewer', 'doc:2')],
            ),
            new WriteOptions(transaction: new TransactionOptions(disable: true)),
        )];
        yield 'readChanges' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->readChanges(type: $text)];
        yield 'check' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->check(new ClientCheckRequest($text, 'viewer', 'doc:1'))];
        yield 'batchCheck' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->batchCheck([
            new ClientBatchCheckItem($text, 'viewer', 'doc:1'),
            new ClientBatchCheckItem($text, 'editor', 'doc:1'),
        ], new BatchCheckOptions(maxBatchSize: 1, maxParallelRequests: 2))];
        yield 'listRelations' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->listRelations(
            new ClientListRelationsRequest($text, 'doc:1', ['viewer']),
        )];
        yield 'expand' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->expand(
            new ExpandBody(tupleKey: new ExpandRequestTupleKey(object: $text, relation: 'viewer')),
        )];
        yield 'listObjects' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->listObjects(
            new ListObjectsBody(relation: 'viewer', type: 'document', user: $text),
        )];
        yield 'streamedListObjects' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => iterator_to_array($fga->streamedListObjects(
            new ListObjectsBody(relation: 'viewer', type: 'document', user: $text),
        ))];
        yield 'listUsers' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->listUsers(new ListUsersBody(
            object: new FgaObject(id: $text, type: 'document'),
            relation: 'viewer',
            userFilters: [new UserTypeFilter(type: 'user')],
        ))];
        yield 'readAssertions' => [static fn(OpenFgaClientInterface $fga): mixed => $fga->readAssertions()];
        yield 'writeAssertions' => [static function (OpenFgaClientInterface $fga): mixed {
            $fga->writeAssertions([]);

            return null;
        }];
        yield 'executeApiRequest' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => $fga->executeApiRequest(
            'POST',
            '/stores/{store_id}/check',
            ['store_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            body: ['user' => $text],
        )];
        yield 'executeStreamedApiRequest' => [static fn(OpenFgaClientInterface $fga, string $text): mixed => iterator_to_array($fga->executeStreamedApiRequest(
            'POST',
            '/stores/{store_id}/streamed-list-objects',
            ['store_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            body: ['user' => $text],
        ))];
    }

    /**
     * @param \Closure(OpenFgaClientInterface, string): mixed $operation
     */
    #[DataProvider('operations')]
    public function testANetworkFailureIsAnFgaException(\Closure $operation): void
    {
        $this->assertOnlyFgaExceptions($operation, $this->openFgaClientFromHttp(new FailingHttpClient(network: true)), 'user:anne');
    }

    /**
     * @param \Closure(OpenFgaClientInterface, string): mixed $operation
     */
    #[DataProvider('operations')]
    public function testAClientFailureIsAnFgaException(\Closure $operation): void
    {
        $this->assertOnlyFgaExceptions($operation, $this->openFgaClientFromHttp(new FailingHttpClient(network: false)), 'user:anne');
    }

    /**
     * @param \Closure(OpenFgaClientInterface, string): mixed $operation
     */
    #[DataProvider('operations')]
    public function testANonJsonSuccessIsAnFgaExceptionOrIgnored(\Closure $operation): void
    {
        $mock = new MockClient();
        $mock->setDefaultResponse(new Response(200, [], '<html>'));

        $this->assertOnlyFgaExceptions($operation, $this->openFgaClient($mock), 'user:anne');
    }

    /**
     * @param \Closure(OpenFgaClientInterface, string): mixed $operation
     */
    #[DataProvider('operations')]
    public function testMalformedUtf8InputIsAnFgaExceptionOrIgnored(\Closure $operation): void
    {
        $mock = new MockClient();
        $mock->setDefaultResponse(new Response(200, [], '{}'));

        $this->assertOnlyFgaExceptions($operation, $this->openFgaClient($mock), self::MALFORMED_UTF8);
    }

    /**
     * @param \Closure(OpenFgaClientInterface, string): mixed $operation
     */
    private function assertOnlyFgaExceptions(\Closure $operation, OpenFgaClientInterface $fga, string $text): void
    {
        $thrown = null;
        try {
            $operation($fga, $text);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        self::assertThat(
            $thrown,
            self::logicalOr(self::isNull(), self::isInstanceOf(FgaException::class)),
            $thrown === null ? '' : sprintf('%s: %s', $thrown::class, $thrown->getMessage()),
        );
    }
}

final readonly class FailingHttpClient implements ClientInterface
{
    public function __construct(private bool $network) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->network) {
            throw new TestNetworkException('connection reset', $request);
        }

        throw new TestClientException('client misconfigured');
    }
}
