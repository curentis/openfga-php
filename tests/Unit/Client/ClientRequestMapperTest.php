<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\ClientRequestMapper;
use Curentis\OpenFga\Client\Options\ConflictOptions;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use PHPUnit\Framework\TestCase;

final class ClientRequestMapperTest extends TestCase
{
    public function testConflictOptionsSerializeToWriteBody(): void
    {
        $body = ClientRequestMapper::toWriteBody(
            new ClientWriteRequest(
                writes: [new ClientTupleKey('user:u', 'viewer', 'doc:1')],
                deletes: [new ClientTupleKeyWithoutCondition('user:u', 'viewer', 'doc:2')],
            ),
            'model-1',
            new ConflictOptions(onDuplicateWrites: 'ignore', onMissingDeletes: 'ignore'),
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
