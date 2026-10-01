<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Model;

use Curentis\OpenFga\Model\TupleKey;
use Curentis\OpenFga\Model\TypeDefinition;
use PHPUnit\Framework\TestCase;

final class NullableNestedObjectTest extends TestCase
{
    public function testTupleKeyAcceptsExplicitNullCondition(): void
    {
        $key = TupleKey::fromArray([
            'user' => 'user:anne',
            'relation' => 'viewer',
            'object' => 'document:1',
            'condition' => null,
        ]);

        self::assertNull($key->condition);
    }

    public function testTypeDefinitionAcceptsExplicitNullMetadata(): void
    {
        $definition = TypeDefinition::fromArray([
            'type' => 'user',
            'metadata' => null,
        ]);

        self::assertNull($definition->metadata);
    }
}
