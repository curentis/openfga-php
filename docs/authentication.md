# Authentication

OpenFGA may require an API token or OAuth2 access token on each request. Configure credentials on `ClientConfiguration`; the SDK attaches `Authorization` headers and caches OAuth tokens when configured.

## No credentials (local / open server)

Default for local OpenFGA without auth:

```php
use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Credentials\NoCredentials;

new ClientConfiguration(
    apiUrl: 'http://localhost:8080',
    credentials: new NoCredentials(),
);
```

## Static API token

For deployments that accept a pre-shared API token (Bearer):

```php
use Curentis\OpenFga\Credentials\ApiToken;

new ClientConfiguration(
    apiUrl: 'https://api.us1.fga.dev',
    credentials: new ApiToken(getenv('FGA_API_TOKEN') ?: ''),
);
```

Example script: [examples/authentication_api_token.php](../examples/authentication_api_token.php).

## OAuth client credentials

Client id and secret exchanged for an access token at your issuer’s token endpoint:

```php
use Curentis\OpenFga\Credentials\ClientCredentials;

new ClientConfiguration(
    apiUrl: 'https://api.us1.fga.dev',
    credentials: new ClientCredentials(
        clientId: getenv('FGA_CLIENT_ID') ?: '',
        clientSecret: getenv('FGA_CLIENT_SECRET') ?: '',
        apiTokenIssuer: 'https://auth.example.com',
        apiAudience: 'https://api.us1.fga.dev/',
        scopes: 'openid', // optional
    ),
);
```

Tokens are fetched automatically before store-scoped calls. Optional **PSR-16** cache:

```php
use Symfony\Component\Cache\Psr16Cache;
// ...
tokenCache: new Psr16Cache($symfonyCachePool),
```

Example script: [examples/authentication_client_credentials.php](../examples/authentication_client_credentials.php).

## Client assertion (JWT bearer)

For OAuth flows that use a signed JWT instead of a client secret:

```php
use Curentis\OpenFga\Credentials\ClientAssertion;

new ClientConfiguration(
    credentials: new ClientAssertion(
        clientId: 'my-client',
        privateKeyPem: file_get_contents('/path/to/private.pem'),
        apiTokenIssuer: 'https://auth.example.com',
        apiAudience: 'https://api.us1.fga.dev/',
        keyId: 'optional-key-id',
    ),
);
```

Only **RS256** is supported for the assertion algorithm.

## Injecting a custom HTTP client

Useful for corporate proxies, custom TLS, or testing:

```php
use GuzzleHttp\Client as GuzzleClient;
use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;

$guzzle = new GuzzleClient(['timeout' => 30]);
$factories = new Psr17Factory();

new ClientConfiguration(
    httpClient: new GuzzleAdapter($guzzle),
    requestFactory: $factories,
    streamFactory: $factories,
);
```

If `httpClient`, `requestFactory`, or `streamFactory` are omitted, the SDK uses [php-http/discovery](https://github.com/php-http/discovery).
