<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Http\PathTemplate;
use PHPUnit\Framework\TestCase;

final class PathTemplateTest extends TestCase
{
    public function testExpandsAndEncodesPathParameters(): void
    {
        self::assertSame(
            '/stores/abc%2Fdef/check',
            PathTemplate::expand('/stores/{store_id}/check', ['store_id' => 'abc/def']),
        );
    }

    public function testMissingParameterThrows(): void
    {
        $this->expectException(FgaValidationException::class);
        PathTemplate::expand('/stores/{store_id}/check', []);
    }

    public function testNullParameterThrows(): void
    {
        $this->expectException(FgaValidationException::class);
        PathTemplate::expand('/stores/{store_id}/check', ['store_id' => null]);
    }

    public function testPathWithoutPlaceholdersIsReturnedUnchanged(): void
    {
        self::assertSame('/healthz', PathTemplate::expand('/healthz', []));
    }

}
