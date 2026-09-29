<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\Request\ClientListRelationsRequest;
use Curentis\OpenFga\Exception\FgaValidationException;
use PHPUnit\Framework\TestCase;

final class ListRelationsTest extends TestCase
{
    public function testEmptyRelationsThrows(): void
    {
        $this->expectException(FgaValidationException::class);
        $this->expectExceptionMessage('relations must not be empty');

        new ClientListRelationsRequest('user:u', 'doc:1', []);
    }
}
