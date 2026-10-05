<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\Ulid;
use Curentis\OpenFga\Exception\FgaValidationException;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function testAcceptsACanonicalUlid(): void
    {
        self::assertTrue(Ulid::isValid('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        Ulid::assert('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'storeId');
        self::assertFalse(Ulid::isValid('01ARZ3NDEKTSV4RRFFQ69G5FA'));
    }

    public function testAssertRejectsInvalidValues(): void
    {
        $this->expectException(FgaValidationException::class);
        Ulid::assert('not-a-ulid', 'storeId');
    }
}
