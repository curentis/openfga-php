<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\Request\ClientListRelationsRequest;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class OpenFgaClientListRelationsTest extends MockTransportTestCase
{
    public function testListRelationsMapsBatchCheckResultsByRelationName(): void
    {
        $http = new RelationBatchClient(['viewer' => true, 'editor' => false, 'owner' => true]);
        $client = $this->openFgaClientFromHttp($http);
        $response = $client->listRelations(new ClientListRelationsRequest(
            'user:anne',
            'document:roadmap',
            ['viewer', 'viewer', 'editor', 'owner'],
        ));

        self::assertSame(['viewer', 'owner'], $response->relations);
        self::assertCount(1, $http->requests);
        $body = json_decode((string) $http->requests[0]->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['checks']);
        self::assertCount(3, $body['checks']);
    }

    public function testListRelationsIgnoresMissingOrDeniedResults(): void
    {
        $http = new RelationBatchClient(['viewer' => false]);
        $client = $this->openFgaClientFromHttp($http);
        $response = $client->listRelations(new ClientListRelationsRequest(
            'user:anne',
            'document:roadmap',
            ['viewer', 'editor'],
        ));

        self::assertSame([], $response->relations);
    }
}

final class RelationBatchClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param array<string, bool> $allowedByRelation */
    public function __construct(private readonly array $allowedByRelation) {}

    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayTypeCoercion
     * @psalm-suppress MixedArrayOffset
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $body = json_decode((string) $request->getBody(), true);
        $result = [];
        if (is_array($body) && isset($body['checks']) && is_array($body['checks'])) {
            foreach ($body['checks'] as $check) {
                if (!is_array($check)) {
                    continue;
                }
                $id = $check['correlation_id'] ?? '';
                $relation = '';
                if (isset($check['tuple_key']) && is_array($check['tuple_key'])) {
                    $relation = is_string($check['tuple_key']['relation'] ?? null) ? $check['tuple_key']['relation'] : '';
                }
                if (!is_string($id)) {
                    continue;
                }
                $result[$id] = ['allowed' => ($this->allowedByRelation[$relation] ?? false) === true];
            }
        }

        return new Response(200, [], json_encode(['result' => $result], JSON_THROW_ON_ERROR));
    }
}
