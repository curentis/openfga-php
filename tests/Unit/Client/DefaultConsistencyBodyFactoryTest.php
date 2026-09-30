<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\DefaultConsistencyBodyFactory;
use Curentis\OpenFga\Model\ConsistencyPreference;
use Curentis\OpenFga\Model\ExpandBody;
use Curentis\OpenFga\Model\ExpandRequestTupleKey;
use PHPUnit\Framework\TestCase;

final class DefaultConsistencyBodyFactoryTest extends TestCase
{
    public function testExpandFillsAuthorizationModelIdFromConfiguration(): void
    {
        $factory = new DefaultConsistencyBodyFactory();
        $modelId = '01HZZZZZZZZZZZZZZZZZZZZZZZ';
        $body = $factory->expand(
            new ExpandBody(tupleKey: new ExpandRequestTupleKey(object: 'doc:1', relation: 'viewer')),
            ConsistencyPreference::HIGHER_CONSISTENCY,
            $modelId,
        );

        self::assertSame($modelId, $body->authorizationModelId);
        self::assertSame('HIGHER_CONSISTENCY', $body->consistency);
    }
}
