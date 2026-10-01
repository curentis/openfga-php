<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

final class QueryParameterFilter
{
    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, scalar|null>
     */
    public static function omitNullAndEmpty(array $query): array
    {
        $filtered = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $filtered[$key] = $value;
        }

        return $filtered;
    }
}
