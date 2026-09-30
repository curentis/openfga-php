<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Contract;

use Curentis\OpenFga\Api\OpenFgaApi;
use Curentis\OpenFga\Client\ClientRequestMapper;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\BatchCheckBody;
use Curentis\OpenFga\Model\BatchCheckItem;
use Curentis\OpenFga\Model\CheckRequestTupleKey;
use Curentis\OpenFga\Model\ConsistencyPreference;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use League\OpenAPIValidation\PSR7\RequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

final class OpenApiRequestContractTest extends MockTransportTestCase
{
    private static ?RequestValidator $requestValidator = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$requestValidator = (new ValidatorBuilder())
            ->fromJsonFile(dirname(__DIR__, 2) . '/spec/openapi.json')
            ->getRequestValidator();
    }

    #[DataProvider('endpointProvider')]
    public function testOutgoingRequestMatchesOpenApiSpec(
        string $method,
        \Closure $invoke,
        string $expectedPathSuffix,
    ): void {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"allowed":true,"result":{}}'));

        $api = $this->openFgaApi($mock);
        $storeId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $invoke($api, $storeId);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame(
            '/stores/' . $storeId . $expectedPathSuffix,
            $request->getUri()->getPath(),
        );

        $validator = self::$requestValidator;
        self::assertInstanceOf(RequestValidator::class, $validator);
        $validator->validate($request);
    }

    /**
     * @return iterable<string, array{0: string, 1: \Closure(OpenFgaApi, string): void, 2: string}>
     */
    public static function endpointProvider(): iterable
    {
        yield 'check' => [
            'POST',
            static function (OpenFgaApi $api, string $storeId): void {
                $body = ClientRequestMapper::toCheckBody(
                    new ClientCheckRequest('user:anne', 'viewer', 'document:1'),
                    '01HZZZZZZZZZZZZZZZZZZZZZZZ',
                    ConsistencyPreference::HIGHER_CONSISTENCY,
                );
                $api->check($storeId, $body);
            },
            '/check',
        ];

        yield 'batch-check' => [
            'POST',
            static function (OpenFgaApi $api, string $storeId): void {
                $body = new BatchCheckBody(
                    checks: [
                        new BatchCheckItem(
                            correlationId: 'c1',
                            tupleKey: new CheckRequestTupleKey(
                                user: 'user:anne',
                                relation: 'viewer',
                                object: 'document:1',
                            ),
                        ),
                    ],
                    authorizationModelId: '01HZZZZZZZZZZZZZZZZZZZZZZZ',
                    consistency: ConsistencyPreference::UNSPECIFIED,
                );
                $api->batchCheck($storeId, $body, [
                    'X-OpenFGA-Client-Method' => 'BatchCheck',
                    'X-OpenFGA-Client-Bulk-Request-Id' => '550e8400-e29b-41d4-a716-446655440000',
                ]);
            },
            '/batch-check',
        ];

        yield 'write' => [
            'POST',
            static function (OpenFgaApi $api, string $storeId): void {
                $body = ClientRequestMapper::toWriteBody(
                    new ClientWriteRequest(writes: [
                        new ClientTupleKey('user:anne', 'viewer', 'document:1'),
                    ]),
                    '01HZZZZZZZZZZZZZZZZZZZZZZZ',
                );
                $api->write($storeId, $body);
            },
            '/write',
        ];
    }
}
