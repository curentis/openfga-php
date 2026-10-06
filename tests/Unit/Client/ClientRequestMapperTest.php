<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\ClientRequestMapper;
use Curentis\OpenFga\Client\Options\ConflictOptions;
use Curentis\OpenFga\Client\Options\OnDuplicateWrites;
use Curentis\OpenFga\Client\Options\OnMissingDeletes;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\ConsistencyPreference;
use PHPUnit\Framework\TestCase;

final class ClientRequestMapperTest extends TestCase
{
    public function testToCheckBodyMapsTraceAndConsistency(): void
    {
        $body = ClientRequestMapper::toCheckBody(
            new ClientCheckRequest('user:u', 'viewer', 'doc:1', trace: true),
            'model-1',
            ConsistencyPreference::HIGHER_CONSISTENCY,
        );

        self::assertTrue($body->trace);
        self::assertSame('model-1', $body->authorizationModelId);
        self::assertSame('HIGHER_CONSISTENCY', $body->consistency);
    }

    public function testWriteTupleWithConditionMapsRelationshipCondition(): void
    {
        $body = ClientRequestMapper::toWriteBody(
            new ClientWriteRequest(
                writes: [
                    new ClientTupleKey(
                        'user:u',
                        'viewer',
                        'doc:1',
                        conditionName: 'cond',
                        conditionContext: ['k' => 'v'],
                    ),
                ],
            ),
            null,
        );

        self::assertNotNull($body->writes);
        self::assertNotNull($body->writes->tupleKeys[0]->condition);
        self::assertSame('cond', $body->writes->tupleKeys[0]->condition->name);
    }

    public function testConflictOptionsSerializeToWriteBody(): void
    {
        $body = ClientRequestMapper::toWriteBody(
            new ClientWriteRequest(
                writes: [new ClientTupleKey('user:u', 'viewer', 'doc:1')],
                deletes: [new ClientTupleKeyWithoutCondition('user:u', 'viewer', 'doc:2')],
            ),
            'model-1',
            new ConflictOptions(onDuplicateWrites: OnDuplicateWrites::Ignore, onMissingDeletes: OnMissingDeletes::Ignore),
        );

        self::assertSame('ignore', $body->writes?->onDuplicate);
        self::assertSame('ignore', $body->deletes?->onMissing);
    }

    public function testConflictOptionsOmittedWhenNull(): void
    {
        $body = ClientRequestMapper::toWriteBody(
            new ClientWriteRequest(
                writes: [new ClientTupleKey('user:u', 'viewer', 'doc:1')],
                deletes: [new ClientTupleKeyWithoutCondition('user:u', 'viewer', 'doc:2')],
            ),
            'model-1',
        );

        self::assertNull($body->writes?->onDuplicate);
        self::assertNull($body->deletes?->onMissing);
        self::assertArrayNotHasKey('on_duplicate', $body->writes?->toArray() ?? []);
        self::assertArrayNotHasKey('on_missing', $body->deletes?->toArray() ?? []);
    }
}
