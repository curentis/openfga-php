<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tools\Codegen\Generator;

final class SchemaLoader
{
    /** @var array<string, mixed> */
    private array $spec;

    /** @param array<string, mixed> $spec */
    public function __construct(array $spec)
    {
        $this->spec = $spec;
    }

    public static function fromSpecFile(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Cannot read spec: %s', $path));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Invalid JSON in spec: %s', $path));
        }

        return new self($decoded);
    }

    /** @return array<string, mixed> */
    public function allSchemas(): array
    {
        $schemas = $this->spec['components']['schemas'] ?? [];

        return is_array($schemas) ? $schemas : [];
    }

    /** @return array<string, mixed>|null */
    public function schema(string $name): ?array
    {
        $schemas = $this->allSchemas();

        return isset($schemas[$name]) && is_array($schemas[$name]) ? $schemas[$name] : null;
    }

    public function resolveRef(string $ref): ?array
    {
        if (!str_starts_with($ref, '#/components/schemas/')) {
            return null;
        }

        $name = substr($ref, strlen('#/components/schemas/'));

        return $this->schema($name);
    }

    /**
     * Collect schema names referenced by core (non-AuthZEN) API paths.
     *
     * @return list<string>
     */
    public function collectCoreApiSchemaNames(): array
    {
        $paths = $this->spec['paths'] ?? [];
        if (!is_array($paths)) {
            return [];
        }

        $queue = [];
        foreach ($paths as $path => $item) {
            if (!is_string($path) || !is_array($item)) {
                continue;
            }
            if (self::isAuthZenPath($path)) {
                continue;
            }
            self::collectRefsFromValue($item, $queue);
        }

        $seen = [];
        $names = [];
        while ($queue !== []) {
            $ref = array_shift($queue);
            if (!is_string($ref) || isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;
            if (!str_starts_with($ref, '#/components/schemas/')) {
                continue;
            }
            $name = substr($ref, strlen('#/components/schemas/'));
            if (self::isAuthZenSchemaName($name)) {
                continue;
            }
            $names[] = $name;
            $schema = $this->schema($name);
            if ($schema !== null) {
                self::collectRefsFromValue($schema, $queue);
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    private static function isAuthZenPath(string $path): bool
    {
        return str_contains($path, '/access/v1/')
            || str_contains($path, 'authzen');
    }

    private static function isAuthZenSchemaName(string $name): bool
    {
        static $prefixes = ['Action', 'Evaluation', 'Resource', 'Subject', 'GetConfiguration'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return $name === 'EvaluationsSemantic';
    }

    /**
     * @param array<string, mixed> $queueRefs ref => true (list as values)
     * @param list<string> $queue
     */
    private static function collectRefsFromValue(mixed $value, array &$queue): void
    {
        if (is_array($value)) {
            if (isset($value['$ref']) && is_string($value['$ref'])) {
                $queue[] = $value['$ref'];
            }
            foreach ($value as $v) {
                self::collectRefsFromValue($v, $queue);
            }
        }
    }
}
