<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Http\JsonBody;
use PHPUnit\Framework\TestCase;

final class JsonBodyTest extends TestCase
{
    public function testEncodeEmptyArrayAsObject(): void
    {
        self::assertSame('{}', JsonBody::encode([]));
    }

    public function testEncodeEmptyStdClassAsObject(): void
    {
        self::assertSame('{}', JsonBody::encode(new \stdClass()));
    }

    public function testEncodeJsonSerializable(): void
    {
        $value = new class implements \JsonSerializable {
            /**
             * @return array<string, int>
             */
            #[\Override]
            public function jsonSerialize(): array
            {
                return ['a' => 1];
            }
        };

        self::assertSame('{"a":1}', JsonBody::encode($value));
    }

    public function testEncodeScalarArray(): void
    {
        self::assertSame('{"k":"v"}', JsonBody::encode(['k' => 'v']));
        self::assertSame('{"path":"a/b"}', JsonBody::encode(['path' => 'a/b']));
    }

    public function testDecodeObject(): void
    {
        self::assertSame(['ok' => true], JsonBody::decode('{"ok":true}'));
    }

    public function testDecodeNonObjectThrows(): void
    {
        $this->expectException(\JsonException::class);
        $this->expectExceptionMessage('Expected JSON object');
        JsonBody::decode('"scalar"');
    }
}
