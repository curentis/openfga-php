<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tools\Codegen\Generator;

final class CodegenRunner
{
    public function __construct(
        private readonly SchemaLoader $loader,
        private readonly string $sourceSha,
        /** @var list<string> */
        private readonly array $allowedSchemaNames,
    ) {}

    /**
     * @return array<string, string> relative path => contents
     */
    public function generateFiles(): array
    {
        $this->assertAllowedSchemasExist();

        /** @var array<string, string> $classNames */
        $classNames = [];
        foreach ($this->allowedSchemaNames as $name) {
            $classNames[$name] = NameConverter::schemaToClassName($name);
        }

        $typeMapper = new TypeMapper($this->loader, $classNames);
        $classRenderer = new ClassRenderer($this->loader, $typeMapper, $this->sourceSha);
        $enumRenderer = new EnumRenderer($this->sourceSha);

        $files = [];
        $enums = [];
        $objects = [];

        foreach ($this->allowedSchemaNames as $name) {
            $schema = $this->loader->schema($name);
            if ($schema === null) {
                throw new \RuntimeException(sprintf('Schema not found: %s', $name));
            }
            if (isset($schema['enum'])) {
                $enums[$name] = $schema;
            } else {
                $objects[$name] = $schema;
            }
        }

        ksort($enums);
        ksort($objects);

        foreach ($enums as $name => $schema) {
            $class = NameConverter::schemaToClassName($name);
            $files['src/Model/' . $class . '.php'] = $enumRenderer->render($name, $schema);
        }

        foreach ($objects as $name => $schema) {
            $class = NameConverter::schemaToClassName($name);
            $files['src/Model/' . $class . '.php'] = $classRenderer->render($name, $schema);
        }

        return $files;
    }

    private function assertAllowedSchemasExist(): void
    {
        $discovered = $this->loader->collectCoreApiSchemaNames();
        $missing = array_diff($discovered, $this->allowedSchemaNames);
        if ($missing !== []) {
            sort($missing);
            throw new \RuntimeException(
                'schemas.php is missing names referenced by core API paths: ' . implode(', ', $missing),
            );
        }
    }

    public static function readSourceSha(string $sourceFile): string
    {
        $line = trim((string) file_get_contents($sourceFile));
        if (preg_match('/@([a-f0-9]{40})/', $line, $m)) {
            return $m[1];
        }

        throw new \RuntimeException('Cannot parse commit SHA from spec/SOURCE');
    }
}
