<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tools\Codegen\Generator;

/**
 * Maps OpenAPI schema fragments to PHP types for generated models.
 */
final class TypeMapper
{
    public function __construct(
        private readonly SchemaLoader $loader,
        /** @var array<string, string> schema name => class short name */
        private readonly array $schemaClassNames,
    ) {}

    /**
     * @param array<string, mixed> $schema
     *
     * @return array{
     *     phpType: string,
     *     nullable: bool,
     *     enumSchema: ?string,
     *     modelSchema: ?string,
     *     isList: bool,
     *     isEmptyObject: bool,
     *     isFreeFormObject: bool,
     *     isDateTime: bool,
     *     imports: list<string>
     * }
     */
    public function map(string $jsonProperty, array $schema, bool $required): array
    {
        $refName = $this->refNameFromSchema($schema);
        $resolved = $this->resolveSchema($schema);
        $nullable = !$required || ($resolved['nullable'] ?? false);

        if (isset($resolved['enum']) && is_array($resolved['enum'])) {
            if ($refName !== null) {
                return $this->result(
                    phpType: NameConverter::schemaToClassName($refName),
                    nullable: $nullable,
                    enumSchema: $refName,
                    modelSchema: null,
                    isList: false,
                    isEmptyObject: false,
                    isFreeFormObject: false,
                    isDateTime: false,
                    imports: [],
                );
            }

            /** @var list<string> $values */
            $values = array_values(array_filter($resolved['enum'], static fn(mixed $v): bool => is_string($v)));

            return $this->result(
                phpType: 'string',
                nullable: $nullable,
                enumSchema: null,
                modelSchema: null,
                isList: false,
                isEmptyObject: false,
                isFreeFormObject: false,
                isDateTime: false,
                imports: [],
                enumValues: $values,
            );
        }

        $type = $resolved['type'] ?? null;
        if ($type === 'string' && ($resolved['format'] ?? '') === 'date-time') {
            return $this->result('\\DateTimeImmutable', $nullable, null, null, false, false, false, true, ['\\DateTimeImmutable']);
        }

        if ($type === 'string') {
            return $this->result('string', $nullable, null, null, false, false, false, false, []);
        }
        if ($type === 'integer') {
            return $this->result('int', $nullable, null, null, false, false, false, false, []);
        }
        if ($type === 'number') {
            return $this->result('float', $nullable, null, null, false, false, false, false, []);
        }
        if ($type === 'boolean') {
            return $this->result('bool', $nullable, null, null, false, false, false, false, []);
        }

        if ($type === 'array') {
            $items = $resolved['items'] ?? $schema['items'] ?? [];
            if (!is_array($items)) {
                return $this->result('array', $nullable, null, null, true, false, true, false, []);
            }
            $itemMapped = $this->map($jsonProperty . 'Item', $items, true);
            $itemModel = $itemMapped['modelSchema'] ?? $this->refFromAllOf($items);

            return $this->result(
                'array',
                $nullable,
                null,
                $itemModel,
                true,
                false,
                false,
                false,
                $itemMapped['imports'],
                itemPhpType: $itemMapped['phpType'],
                itemEnum: $itemMapped['enumSchema'],
            );
        }

        if ($type === 'object' || isset($resolved['properties']) || isset($resolved['additionalProperties'])) {
            $props = $resolved['properties'] ?? null;
            if (is_array($props) && $props === []) {
                return $this->result('\\stdClass', $nullable, null, null, false, true, false, false, []);
            }
            $refName = $this->refNameFromSchema($schema)
                ?? $this->refNameFromSchema($resolved)
                ?? $this->refFromAllOf($schema)
                ?? $this->refFromAllOf($resolved);
            if ($refName !== null && is_array($props) && $props !== []) {
                $class = $this->schemaClassNames[$refName] ?? NameConverter::schemaToClassName($refName);

                return $this->result($class, $nullable, null, $refName, false, false, false, false, []);
            }
            if (isset($resolved['additionalProperties']) || $props === null) {
                return $this->result('array', $nullable, null, null, false, false, true, false, []);
            }
        }

        $refName = $this->refNameFromSchema($schema);
        if ($refName !== null) {
            $class = $this->schemaClassNames[$refName] ?? NameConverter::schemaToClassName($refName);

            return $this->result($class, $nullable, null, $refName, false, false, false, false, []);
        }

        return $this->result('mixed', $nullable, null, null, false, false, false, false, []);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public function resolveSchema(array $schema): array
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $resolved = $this->loader->resolveRef($schema['$ref']);

            return $resolved ?? $schema;
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = [];
            foreach ($schema['allOf'] as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $merged = array_merge($merged, $this->resolveSchema($part));
            }

            return $merged;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function refNameFromSchema(array $schema): ?string
    {
        if (isset($schema['$ref']) && is_string($schema['$ref']) && str_starts_with($schema['$ref'], '#/components/schemas/')) {
            return substr($schema['$ref'], strlen('#/components/schemas/'));
        }

        return null;
    }

    /** @param array<string, mixed> $schema */
    private function refFromAllOf(array $schema): ?string
    {
        if (!isset($schema['allOf']) || !is_array($schema['allOf'])) {
            return null;
        }
        foreach ($schema['allOf'] as $part) {
            if (!is_array($part)) {
                continue;
            }
            $name = $this->refNameFromSchema($part);
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param list<string> $imports
     *
     * @return array{
     *     phpType: string,
     *     nullable: bool,
     *     enumSchema: ?string,
     *     modelSchema: ?string,
     *     isList: bool,
     *     isEmptyObject: bool,
     *     isFreeFormObject: bool,
     *     isDateTime: bool,
     *     imports: list<string>,
     *     itemPhpType: string,
     *     itemEnum: ?string
     * }
     */
    private function result(
        string $phpType,
        bool $nullable,
        ?string $enumSchema,
        ?string $modelSchema,
        bool $isList,
        bool $isEmptyObject,
        bool $isFreeFormObject,
        bool $isDateTime,
        array $imports,
        string $itemPhpType = 'mixed',
        ?string $itemEnum = null,
        /** @var list<string> */
        array $enumValues = [],
    ): array {
        return [
            'phpType' => $phpType,
            'nullable' => $nullable,
            'enumSchema' => $enumSchema,
            'modelSchema' => $modelSchema,
            'isList' => $isList,
            'isEmptyObject' => $isEmptyObject,
            'isFreeFormObject' => $isFreeFormObject,
            'isDateTime' => $isDateTime,
            'imports' => $imports,
            'itemPhpType' => $itemPhpType,
            'itemEnum' => $itemEnum,
            'enumValues' => $enumValues,
        ];
    }
}
