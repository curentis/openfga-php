<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tools\Codegen\Generator;

final class ClassRenderer
{
    public function __construct(
        private readonly SchemaLoader $loader,
        private readonly TypeMapper $typeMapper,
        private readonly string $sourceSha,
    ) {}

    /**
     * @param array<string, mixed> $schema
     */
    public function render(string $schemaName, array $schema): string
    {
        $className = NameConverter::schemaToClassName($schemaName);
        $resolved = $this->typeMapper->resolveSchema($schema);
        $properties = $resolved['properties'] ?? [];
        if (!is_array($properties)) {
            $properties = [];
        }

        $required = $resolved['required'] ?? [];
        if (!is_array($required)) {
            $required = [];
        }
        $requiredSet = array_fill_keys($required, true);

        $propNames = array_keys($properties);
        usort($propNames, static function (string $a, string $b) use ($requiredSet): int {
            $aReq = isset($requiredSet[$a]) ? 0 : 1;
            $bReq = isset($requiredSet[$b]) ? 0 : 1;
            if ($aReq !== $bReq) {
                return $aReq <=> $bReq;
            }

            return strcmp($a, $b);
        });

        $ctorParams = [];
        $fromArrayLines = [];
        $toArrayEntries = [];
        $uses = [
            'Curentis\\OpenFga\\Exception\\FgaValidationException' => true,
        ];

        foreach ($propNames as $jsonName) {
            if (!is_string($jsonName)) {
                continue;
            }
            $propSchema = $properties[$jsonName];
            if (!is_array($propSchema)) {
                continue;
            }
            $phpName = NameConverter::jsonPropertyToPhp($jsonName);
            $isRequired = isset($requiredSet[$jsonName]);
            $mapped = $this->typeMapper->map($jsonName, $propSchema, $isRequired);
            foreach ($mapped['imports'] as $import) {
                if ($import !== '' && !str_starts_with($import, '\\')) {
                    $uses['Curentis\\OpenFga\\Model\\' . $import] = true;
                }
            }
            if ($mapped['modelSchema'] !== null && !$mapped['isList']) {
                $uses['Curentis\\OpenFga\\Model\\' . $mapped['phpType']] = true;
            }
            if ($mapped['isList'] && $mapped['modelSchema'] !== null) {
                $uses['Curentis\\OpenFga\\Model\\' . NameConverter::schemaToClassName($mapped['modelSchema'])] = true;
            }
            if ($mapped['enumSchema'] !== null) {
                $uses['Curentis\\OpenFga\\Model\\' . $mapped['phpType']] = true;
            }

            $phpType = $mapped['phpType'];
            if ($mapped['nullable']) {
                $phpType = '?' . ltrim($phpType, '?');
            }

            $default = $isRequired ? '' : ' = null';
            $doc = $this->propertyDocblock($mapped);
            $ctorParams[] = $doc . sprintf('public %s $%s%s', $phpType, $phpName, $default);

            $fromArrayLines = array_merge(
                $fromArrayLines,
                $this->renderFromArrayValidation($jsonName, $phpName, $mapped, $isRequired),
            );

            $toArrayEntries[] = $this->renderToArrayEntry($jsonName, $phpName, $mapped);
        }

        $useStatements = '';
        unset($uses['Curentis\\OpenFga\\Model\\' . $className]);
        ksort($uses);
        foreach (array_keys($uses) as $use) {
            $useStatements .= 'use ' . $use . ";\n";
        }

        $ctorBody = implode(",\n            ", $ctorParams);
        $fromArrayBody = implode("\n            ", $fromArrayLines);
        $returnNew = $this->renderReturnNew($propNames);
        $toArrayBody = implode(', ', $toArrayEntries);

        $header = $this->fileHeader();

        return <<<PHP
            {$header}
            namespace Curentis\\OpenFga\\Model;

            {$useStatements}
            final readonly class {$className} implements \\JsonSerializable
            {
                public function __construct(
                        {$ctorBody}
                ) {
                }

                /** @param array<array-key, mixed> \$data */
                public static function fromArray(array \$data, string \$path = ''): self
                {
                        {$fromArrayBody}
                        {$returnNew}
                }

                /** @return array<string, mixed> */
                public function toArray(): array
                {
                    return array_filter([{$toArrayBody}], static fn (mixed \$v): bool => \$v !== null);
                }

                /** @return array<string, mixed> */
                public function jsonSerialize(): array
                {
                    return \$this->toArray();
                }
            }

            PHP;
    }

    /** @param list<string> $propNames */
    private function renderReturnNew(array $propNames): string
    {
        $args = [];
        foreach ($propNames as $jsonName) {
            $phpName = NameConverter::jsonPropertyToPhp($jsonName);
            $args[] = sprintf('%s: $%s', $phpName, $phpName);
        }

        return 'return new self(' . implode(', ', $args) . ');';
    }

    /**
     * @param array{
     *     phpType: string,
     *     nullable: bool,
     *     enumSchema: ?string,
     *     modelSchema: ?string,
     *     isList: bool,
     *     isEmptyObject: bool,
     *     isFreeFormObject: bool,
     *     isDateTime: bool,
     *     imports: list<string>
     * } $mapped
     *
     * @return list<string>
     */
    private function renderFromArrayValidation(string $jsonName, string $phpName, array $mapped, bool $isRequired): array
    {
        $lines = [];
        $pathExpr = "(\$path === '' ? '' : \$path . '.') . '" . addslashes($jsonName) . "'";

        if ($isRequired) {
            $lines[] = sprintf(
                'if (!array_key_exists(\'%s\', $data)) { throw new FgaValidationException(sprintf(\'%s: required\', %s)); }',
                addslashes($jsonName),
                '%s',
                $pathExpr,
            );
            $lines = array_merge($lines, $this->renderTypeCheck($jsonName, $phpName, $mapped, $pathExpr, true));

            return $lines;
        }

        $lines[] = sprintf('$%s = null;', $phpName);
        $lines[] = sprintf('if (array_key_exists(\'%s\', $data)) {', addslashes($jsonName));
        $lines[] = sprintf('    $%s = $data[\'%s\'];', $phpName, addslashes($jsonName));
        $inner = $this->renderTypeCheck($jsonName, $phpName, $mapped, $pathExpr, false);
        $lines[] = '    ' . implode("\n            ", $inner);
        $lines[] = '}';

        return $lines;
    }

    /**
     * @param array{
     *     phpType: string,
     *     nullable: bool,
     *     enumSchema: ?string,
     *     modelSchema: ?string,
     *     isList: bool,
     *     isEmptyObject: bool,
     *     isFreeFormObject: bool,
     *     isDateTime: bool,
     *     imports: list<string>
     * } $mapped
     *
     * @return list<string>
     */
    private function renderTypeCheck(string $jsonName, string $phpName, array $mapped, string $pathExpr, bool $isRequired): array
    {
        $valueExpr = $isRequired ? sprintf('$data[\'%s\']', addslashes($jsonName)) : sprintf('$%s', $phpName);

        if ($mapped['isDateTime']) {
            return [
                sprintf('if (!is_string(%s)) { throw new FgaValidationException(sprintf(\'%s: expected date-time string\', %s)); }', $valueExpr, '%s', $pathExpr),
                sprintf('$%s = new \\DateTimeImmutable(%s);', $phpName, $valueExpr),
            ];
        }

        if ($mapped['enumSchema'] !== null) {
            $enumClass = $mapped['phpType'];

            return [
                sprintf('if (!is_string(%s)) { throw new FgaValidationException(sprintf(\'%s: expected string\', %s)); }', $valueExpr, '%s', $pathExpr),
                sprintf('try { $%s = %s::from(%s); } catch (\\ValueError) { throw new FgaValidationException(sprintf(\'%s: invalid enum value\', %s)); }', $phpName, $enumClass, $valueExpr, '%s', $pathExpr),
            ];
        }

        if (($mapped['enumValues'] ?? []) !== []) {
            $allowed = var_export(array_values($mapped['enumValues']), true);

            return [
                sprintf('if (!is_string(%s)) { throw new FgaValidationException(sprintf(\'%s: expected string\', %s)); }', $valueExpr, '%s', $pathExpr),
                sprintf('if (!in_array(%s, %s, true)) { throw new FgaValidationException(sprintf(\'%s: invalid enum value\', %s)); }', $valueExpr, $allowed, '%s', $pathExpr),
                ...($isRequired ? [sprintf('$%s = %s;', $phpName, $valueExpr)] : []),
            ];
        }

        if ($mapped['isList']) {
            $listVar = $phpName . 'List';

            return [
                sprintf('if (!is_array(%s)) { throw new FgaValidationException(sprintf(\'%s: expected array\', %s)); }', $valueExpr, '%s', $pathExpr),
                sprintf('$%s = [];', $listVar),
                sprintf('foreach (%s as $idx => $item) {', $valueExpr),
                ...$this->renderListItemAssignment($listVar, $mapped),
                '}',
                sprintf('$%s = $%s;', $phpName, $listVar),
            ];
        }

        if ($mapped['modelSchema'] !== null) {
            $class = $mapped['phpType'];

            return [
                sprintf('if (!is_array(%s)) { throw new FgaValidationException(sprintf(\'%s: expected object\', %s)); }', $valueExpr, '%s', $pathExpr),
                sprintf('$%s = %s::fromArray(%s, %s);', $phpName, $class, $valueExpr, $pathExpr),
            ];
        }

        if ($mapped['isEmptyObject']) {
            return [
                sprintf('if (!is_array(%s) && !($%s instanceof \\stdClass)) { throw new FgaValidationException(sprintf(\'%s: expected object\', %s)); }', $valueExpr, $isRequired ? 'data' : $phpName, '%s', $pathExpr),
                sprintf('$%s = is_array(%s) ? (object) %s : %s;', $phpName, $valueExpr, $valueExpr, $valueExpr),
            ];
        }

        if ($mapped['isFreeFormObject'] || ($mapped['phpType'] === 'array' && !$mapped['isList'])) {
            return [
                sprintf('if (!is_array(%s)) { throw new FgaValidationException(sprintf(\'%s: expected object\', %s)); }', $valueExpr, '%s', $pathExpr),
            ];
        }

        $phpScalar = match ($mapped['phpType']) {
            'string' => 'string',
            'int' => 'integer',
            'float' => ['double', 'integer'],
            'bool' => 'bool',
            default => null,
        };

        if ($phpScalar === null) {
            return [];
        }

        if (is_array($phpScalar)) {
            $checks = implode(' || ', array_map(static fn(string $t): string => sprintf('is_%s(%s)', $t, $valueExpr), $phpScalar));

            return [sprintf('if (!(%s)) { throw new FgaValidationException(sprintf(\'%s: expected number\', %s)); }', $checks, '%s', $pathExpr)];
        }

        $line = sprintf('if (!is_%s(%s)) { throw new FgaValidationException(sprintf(\'%s: expected %s\', %s)); }', $phpScalar, $valueExpr, '%s', $mapped['phpType'], $pathExpr);
        if ($isRequired && in_array($mapped['phpType'], ['string', 'int', 'float', 'bool'], true)) {
            return [$line, sprintf('$%s = %s;', $phpName, $valueExpr)];
        }

        return [$line];
    }

    /**
     * @param array{
     *     modelSchema: ?string,
     *     itemPhpType: string,
     *     itemEnum: ?string
     * } $mapped
     *
     * @return list<string>
     */
    private function renderListItemAssignment(string $phpName, array $mapped): array
    {
        $itemType = $mapped['itemPhpType'] ?? 'mixed';
        if ($mapped['modelSchema'] !== null) {
            $class = NameConverter::schemaToClassName($mapped['modelSchema']);

            return [
                '    if (!is_array($item)) { throw new FgaValidationException(sprintf(\'%s: expected object\', ($path === \'\' ? \'\' : $path . \'.\') . $idx)); }',
                sprintf('    $%s[] = %s::fromArray($item, ($path === \'\' ? \'\' : $path . \'.\') . (string) $idx);', $phpName, $class),
            ];
        }
        if ($itemType === 'string') {
            return [
                '    if (!is_string($item)) { throw new FgaValidationException(sprintf(\'%s: expected string\', ($path === \'\' ? \'\' : $path . \'.\') . $idx)); }',
                sprintf('    $%s[] = $item;', $phpName),
            ];
        }
        if ($itemType === 'int') {
            return [
                '    if (!is_int($item)) { throw new FgaValidationException(sprintf(\'%s: expected int\', ($path === \'\' ? \'\' : $path . \'.\') . $idx)); }',
                sprintf('    $%s[] = $item;', $phpName),
            ];
        }

        return [
            '    $' . $phpName . '[] = $item;',
        ];
    }

    /**
     * @param array{
     *     phpType: string,
     *     nullable: bool,
     *     enumSchema: ?string,
     *     modelSchema: ?string,
     *     isList: bool,
     *     isEmptyObject: bool,
     *     isFreeFormObject: bool,
     *     isDateTime: bool,
     *     imports: list<string>
     * } $mapped
     */
    private function renderToArrayEntry(string $jsonName, string $phpName, array $mapped): string
    {
        if ($mapped['isDateTime']) {
            return sprintf('\'%s\' => $this->%s->format(\\DateTimeInterface::ATOM)', addslashes($jsonName), $phpName);
        }
        if ($mapped['enumSchema'] !== null) {
            if ($mapped['nullable']) {
                return sprintf('\'%s\' => $this->%s?->value', addslashes($jsonName), $phpName);
            }

            return sprintf('\'%s\' => $this->%s->value', addslashes($jsonName), $phpName);
        }
        if ($mapped['isList'] && $mapped['modelSchema'] !== null) {
            $class = NameConverter::schemaToClassName($mapped['modelSchema']);
            if ($mapped['nullable']) {
                return sprintf(
                    '\'%s\' => $this->%s === null ? null : array_map(static fn (%s $v): array => $v->toArray(), $this->%s)',
                    addslashes($jsonName),
                    $phpName,
                    $class,
                    $phpName,
                );
            }

            return sprintf(
                '\'%s\' => array_map(static fn (%s $v): array => $v->toArray(), $this->%s)',
                addslashes($jsonName),
                $class,
                $phpName,
            );
        }
        if ($mapped['modelSchema'] !== null) {
            $access = $mapped['nullable']
                ? sprintf('$this->%s?->toArray()', $phpName)
                : sprintf('$this->%s->toArray()', $phpName);

            return sprintf('\'%s\' => %s', addslashes($jsonName), $access);
        }
        if ($mapped['isEmptyObject']) {
            return sprintf('\'%s\' => json_decode(json_encode($this->%s, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR)', addslashes($jsonName), $phpName);
        }

        return sprintf('\'%s\' => $this->%s', addslashes($jsonName), $phpName);
    }

    /**
     * @param array{
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
     * } $mapped
     */
    private function propertyDocblock(array $mapped): string
    {
        if ($mapped['isList'] && $mapped['modelSchema'] !== null) {
            $item = NameConverter::schemaToClassName($mapped['modelSchema']);
            $null = $mapped['nullable'] ? '|null' : '';

            return sprintf('/** @var list<%s>%s */ ', $item, $null);
        }
        if ($mapped['isList']) {
            $null = $mapped['nullable'] ? '|null' : '';

            return sprintf('/** @var list<mixed>%s */ ', $null);
        }
        if ($mapped['isFreeFormObject'] || ($mapped['phpType'] === 'array' && !$mapped['isList'])) {
            $null = $mapped['nullable'] ? '|null' : '';

            return sprintf('/** @var array<string, mixed>%s */ ', $null);
        }

        return '';
    }

    private function fileHeader(): string
    {
        return <<<PHP
            <?php

            // Code generated by tools/codegen. DO NOT EDIT. Source: openfga/api@{$this->sourceSha}
            declare(strict_types=1);


            PHP;
    }
}
