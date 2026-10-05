<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Model;

use Curentis\OpenFga\Exception\FgaResponseDecodeException;
use Curentis\OpenFga\Model\CheckError;
use Curentis\OpenFga\Model\ErrorCode;
use PHPUnit\Framework\TestCase;

final class UnknownEnumRoundTripTest extends TestCase
{
    public function testUnknownResponseEnumValuesStayStrings(): void
    {
        $error = CheckError::fromArray([
            'input_error' => 'future_error_code',
            'message' => 'later',
        ]);

        self::assertSame('future_error_code', $error->inputError);
        self::assertSame(
            ['input_error' => 'future_error_code', 'message' => 'later'],
            $error->toArray(),
        );

        $known = CheckError::fromArray(['input_error' => ErrorCode::VALIDATION_ERROR->value]);
        self::assertSame(ErrorCode::VALIDATION_ERROR, $known->inputError);
        self::assertSame(['input_error' => 'validation_error'], $known->toArray());
    }

    public function testNonStringEnumValuesAreRejected(): void
    {
        $this->expectException(FgaResponseDecodeException::class);
        CheckError::fromArray(['input_error' => 1]);
    }
}
