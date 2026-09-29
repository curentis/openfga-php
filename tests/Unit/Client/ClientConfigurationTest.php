<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Exception\FgaValidationException;
use PHPUnit\Framework\TestCase;

final class ClientConfigurationTest extends TestCase
{
    public function testRejectsInvalidApiUrl(): void
    {
        $this->expectException(FgaValidationException::class);
        new ClientConfiguration(apiUrl: 'not-a-url');
    }

    public function testRejectsInvalidStoreId(): void
    {
        $this->expectException(FgaValidationException::class);
        new ClientConfiguration(storeId: 'not-a-ulid');
    }

    public function testWithStoreIdReturnsNewInstance(): void
    {
        $config = new ClientConfiguration();
        $updated = $config->withStoreId('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        self::assertNull($config->storeId);
        self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $updated->storeId);
    }

    public function testRetryOptionsValidation(): void
    {
        $this->expectException(FgaValidationException::class);
        new RetryOptions(maxRetry: 16);
    }
}
