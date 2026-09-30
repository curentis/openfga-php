<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Options\ConflictOptions;
use Curentis\OpenFga\Client\Options\PaginationOptions;
use Curentis\OpenFga\Client\Options\RequestOptions;
use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Client\Options\TransactionOptions;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Response\ClientBatchCheckResponse;
use Curentis\OpenFga\Client\Response\ClientListRelationsResponse;
use Curentis\OpenFga\Client\Response\ClientWriteResponse;
use Curentis\OpenFga\Client\Response\ClientWriteTupleResult;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Model\BatchCheckSingleResult;
use PHPUnit\Framework\TestCase;

final class ClientResponseAndOptionsTest extends TestCase
{
    public function testResponseValueObjectsExposeProperties(): void
    {
        $tupleResult = new ClientWriteTupleResult('user:u', 'viewer', 'doc:1', 'write', true);
        $write = new ClientWriteResponse(null, [$tupleResult]);
        self::assertTrue($write->tupleResults[0]->success);

        $batch = new ClientBatchCheckResponse([
            new BatchCheckSingleResult(allowed: true),
        ]);
        self::assertTrue($batch->results[0]->allowed);

        self::assertSame(['viewer'], (new ClientListRelationsResponse(['viewer']))->relations);
    }

    public function testOptionsDefaultsAreConstructible(): void
    {
        self::assertSame(50, (new BatchCheckOptions())->maxBatchSize);
        self::assertNull((new PaginationOptions())->pageSize);
        self::assertSame(3, (new RetryOptions())->maxRetry);
        self::assertFalse((new TransactionOptions())->disable);
        self::assertSame([], (new RequestOptions())->headers);
        self::assertNull((new ConflictOptions())->onDuplicateWrites);
        self::assertFalse((new WriteOptions())->transaction->disable);
    }

    public function testWriteOptionsRejectsInvalidMaxPerChunk(): void
    {
        $this->expectException(FgaValidationException::class);
        new WriteOptions(maxPerChunk: 0);
    }

    public function testWriteOptionsAcceptsMinimumMaxPerChunk(): void
    {
        self::assertSame(1, (new WriteOptions(maxPerChunk: 1))->maxPerChunk);
    }

    public function testBatchCheckOptionsRejectsOutOfRangeBatchSize(): void
    {
        $this->expectException(FgaValidationException::class);
        new BatchCheckOptions(maxBatchSize: 0);
    }

    public function testBatchCheckOptionsRejectsBatchSizeAboveFifty(): void
    {
        $this->expectException(FgaValidationException::class);
        new BatchCheckOptions(maxBatchSize: 51);
    }

    public function testRetryOptionsRejectsInvalidMinWait(): void
    {
        $this->expectException(FgaValidationException::class);
        new RetryOptions(minWaitMs: 0);
    }

    public function testRetryOptionsRejectsNegativeMaxRetry(): void
    {
        $this->expectException(FgaValidationException::class);
        new RetryOptions(maxRetry: -1);
    }

    public function testRetryOptionsAcceptsZeroMaxRetry(): void
    {
        self::assertSame(0, (new RetryOptions(maxRetry: 0))->maxRetry);
    }

    public function testRetryOptionsAcceptsFifteenMaxRetry(): void
    {
        self::assertSame(15, (new RetryOptions(maxRetry: 15))->maxRetry);
    }

    public function testRetryOptionsRejectsMaxRetryAboveFifteen(): void
    {
        $this->expectException(FgaValidationException::class);
        new RetryOptions(maxRetry: 16);
    }

    public function testRetryOptionsAcceptsMinimumMinWait(): void
    {
        self::assertSame(1, (new RetryOptions(minWaitMs: 1))->minWaitMs);
    }

    public function testBatchCheckOptionsAcceptsMinimumBatchSize(): void
    {
        self::assertSame(1, (new BatchCheckOptions(maxBatchSize: 1))->maxBatchSize);
    }
}
