<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Http\QueryParameterFilter;
use PHPUnit\Framework\TestCase;

final class QueryParameterFilterTest extends TestCase
{
    public function testOmitsNullAndEmptyStringValues(): void
    {
        $filtered = QueryParameterFilter::omitNullAndEmpty([
            'page_size' => 10,
            'continuation_token' => '',
            'name' => null,
            'type' => 'document',
        ]);

        self::assertSame([
            'page_size' => 10,
            'type' => 'document',
        ], $filtered);
    }

    public function testReturnsEmptyArrayWhenAllValuesAreOmitted(): void
    {
        self::assertSame([], QueryParameterFilter::omitNullAndEmpty([
            'continuation_token' => '',
            'name' => null,
        ]));
    }
}
