<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientListRelationsRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use PHPUnit\Framework\TestCase;

final class ClientRequestTypesTest extends TestCase
{
    public function testRequestValueObjectsHoldTupleData(): void
    {
        $check = new ClientCheckRequest('user:anne', 'viewer', 'document:1');
        self::assertSame('user:anne', $check->user);

        $item = new ClientBatchCheckItem('user:anne', 'viewer', 'document:1', correlationId: 'c1');
        self::assertSame('c1', $item->correlationId);

        $list = new ClientListRelationsRequest('user:anne', 'document:1', ['viewer', 'editor']);
        self::assertSame(['viewer', 'editor'], $list->relations);

        $tuple = new ClientTupleKey('user:anne', 'viewer', 'document:1');
        self::assertSame('document:1', $tuple->object);

        $deleteTuple = new ClientTupleKeyWithoutCondition('user:anne', 'viewer', 'document:1');
        self::assertSame('viewer', $deleteTuple->relation);

        $write = new ClientWriteRequest(writes: [$tuple], deletes: [$deleteTuple]);
        self::assertCount(1, $write->writes);
        self::assertCount(1, $write->deletes);
    }
}
