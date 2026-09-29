<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

use Curentis\OpenFga\Exception\FgaValidationException;

final readonly class ApiToken implements CredentialsInterface
{
    public function __construct(public string $token)
    {
        if ($token === '') {
            throw new FgaValidationException('API token must not be empty.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => '***'];
    }
}
