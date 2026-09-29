<?php

declare(strict_types=1);

use Curentis\OpenFga\Tools\Codegen\Generator\SchemaLoader;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$loader = SchemaLoader::fromSpecFile($root . '/spec/openapi.json');
$names = $loader->collectCoreApiSchemaNames();

$export = var_export($names, true);
$php = <<<PHP
    <?php

    declare(strict_types=1);

    /**
     * Allow-list of OpenAPI schema names generated into src/Model (core FGA API, excludes AuthZEN).
     *
     * @return list<string>
     */
    return {$export};

    PHP;

file_put_contents(__DIR__ . '/schemas.php', $php);
fwrite(STDOUT, 'Wrote ' . count($names) . " schema names to tools/codegen/schemas.php\n");
