<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Exception\FgaValidationException;

/**
 * @internal
 */
final class PathTemplate
{
    /**
     * @param array<string, scalar|null> $pathParams
     */
    public static function expand(string $template, array $pathParams): string
    {
        if (preg_match_all('/\{([^}]+)\}/', $template, $matches) !== false) {
            foreach ($matches[1] as $name) {
                if (!array_key_exists($name, $pathParams)) {
                    throw new FgaValidationException(sprintf('Missing path parameter "%s" for path "%s".', $name, $template));
                }
                $value = $pathParams[$name];
                if ($value === null) {
                    throw new FgaValidationException(sprintf('Path parameter "%s" must not be null.', $name));
                }
                $encoded = rawurlencode((string) $value);
                $template = str_replace('{' . $name . '}', $encoded, $template);
            }
        }

        if (preg_match('/\{[^}]+\}/', $template) === 1) {
            throw new FgaValidationException(sprintf('Unknown path placeholders remain in "%s".', $template));
        }

        return $template;
    }
}
