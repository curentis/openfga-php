<?php

declare(strict_types=1);

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Credentials\ApiToken;

require dirname(__DIR__) . '/vendor/autoload.php';

$token = getenv('FGA_API_TOKEN');
if ($token === false || $token === '') {
    fwrite(STDERR, "Set FGA_API_TOKEN to your OpenFGA API token.\n");
    exit(1);
}

$apiUrl = getenv('FGA_API_URL');
if ($apiUrl === false || $apiUrl === '') {
    $apiUrl = 'https://api.us1.fga.dev';
}

$fga = OpenFgaClientFactory::create(new ClientConfiguration(
    apiUrl: $apiUrl,
    credentials: new ApiToken($token),
));

$stores = $fga->listStores();
echo 'Store count: ', count($stores->stores), PHP_EOL;
