<?php

declare(strict_types=1);

use Curentis\OpenFga\Api\OpenFgaApiInterface;
use Curentis\OpenFga\Client\BatchCheckRunnerInterface;
use Curentis\OpenFga\Client\ClientComponentFactoryInterface;
use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\ConsistencyBodyFactoryInterface;
use Curentis\OpenFga\Client\DefaultClientComponentFactory;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Client\Response\ClientWriteResponse;
use Curentis\OpenFga\Client\WriteRunnerInterface;
use Curentis\OpenFga\Http\TransportInterface;
use Curentis\OpenFga\Model\WriteAuthorizationModelBody;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Adds a custom header on every transactional write for demonstration.
 */
final class HeaderWriteRunner implements WriteRunnerInterface
{
    public function __construct(private readonly WriteRunnerInterface $inner) {}

    public function run(
        string $storeId,
        ClientWriteRequest $request,
        ?string $authorizationModelId,
        WriteOptions $writeOptions,
        array $headers,
        ?\Curentis\OpenFga\Client\Options\RetryOptions $retry = null,
    ): ClientWriteResponse {
        return $this->inner->run(
            $storeId,
            $request,
            $authorizationModelId,
            $writeOptions,
            $headers + ['X-Demo-Component' => 'custom-write-runner'],
        );
    }
}

final class DemoComponentFactory implements ClientComponentFactoryInterface
{
    private readonly DefaultClientComponentFactory $defaults;

    public function __construct()
    {
        $this->defaults = new DefaultClientComponentFactory();
    }

    public function createWriteRunner(OpenFgaApiInterface $api): WriteRunnerInterface
    {
        return new HeaderWriteRunner($this->defaults->createWriteRunner($api));
    }

    public function createBatchCheckRunner(OpenFgaApiInterface $api, TransportInterface $transport): BatchCheckRunnerInterface
    {
        return $this->defaults->createBatchCheckRunner($api, $transport);
    }

    public function createConsistencyBodyFactory(): ConsistencyBodyFactoryInterface
    {
        return $this->defaults->createConsistencyBodyFactory();
    }
}

$apiUrl = getenv('FGA_API_URL');
if ($apiUrl === false || $apiUrl === '') {
    $apiUrl = 'http://localhost:8080';
}

$fga = OpenFgaClientFactory::create(new ClientConfiguration(
    apiUrl: $apiUrl,
    componentFactory: new DemoComponentFactory(),
));

$storeId = $fga->createStore('custom-components-demo')->id;
$fga = $fga->withStoreId($storeId);

$modelId = $fga->writeAuthorizationModel(WriteAuthorizationModelBody::fromArray([
    'schema_version' => '1.1',
    'type_definitions' => [
        ['type' => 'user'],
        [
            'type' => 'document',
            'relations' => ['viewer' => ['this' => new stdClass()]],
            'metadata' => [
                'relations' => [
                    'viewer' => ['directly_related_user_types' => [['type' => 'user']]],
                ],
            ],
        ],
    ],
]))->authorizationModelId;

$fga = $fga->withAuthorizationModelId($modelId);
$fga->writeTuples([new ClientTupleKey('user:demo', 'viewer', 'document:1')]);

echo "Wrote tuple using custom WriteRunner (see X-Demo-Component on the write request in server logs).\n";
