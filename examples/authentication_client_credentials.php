<?php

declare(strict_types=1);

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Credentials\ClientCredentials;

require dirname(__DIR__) . '/vendor/autoload.php';

$clientId = getenv('FGA_CLIENT_ID') ?: '';
$clientSecret = getenv('FGA_CLIENT_SECRET') ?: '';
$issuer = getenv('FGA_TOKEN_ISSUER') ?: '';
$audience = getenv('FGA_TOKEN_AUDIENCE') ?: '';

if ($clientId === '' || $clientSecret === '' || $issuer === '' || $audience === '') {
    fwrite(STDERR, "Set FGA_CLIENT_ID, FGA_CLIENT_SECRET, FGA_TOKEN_ISSUER, and FGA_TOKEN_AUDIENCE.\n");
    exit(1);
}

$apiUrl = getenv('FGA_API_URL');
if ($apiUrl === false || $apiUrl === '') {
    $apiUrl = 'https://api.us1.fga.dev';
}

$fga = OpenFgaClientFactory::create(new ClientConfiguration(
    apiUrl: $apiUrl,
    credentials: new ClientCredentials(
        clientId: $clientId,
        clientSecret: $clientSecret,
        apiTokenIssuer: $issuer,
        apiAudience: $audience,
    ),
));

$stores = $fga->listStores();
echo 'Store count: ', count($stores->stores), PHP_EOL;
