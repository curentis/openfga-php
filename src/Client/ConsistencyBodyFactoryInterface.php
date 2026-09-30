<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Model\ConsistencyPreference;
use Curentis\OpenFga\Model\ExpandBody;
use Curentis\OpenFga\Model\ListObjectsBody;
use Curentis\OpenFga\Model\ListUsersBody;

interface ConsistencyBodyFactoryInterface
{
    public function expand(ExpandBody $body, ConsistencyPreference $consistency, ?string $authorizationModelId): ExpandBody;

    public function listObjects(ListObjectsBody $body, ConsistencyPreference $consistency): ListObjectsBody;

    public function listUsers(ListUsersBody $body, ConsistencyPreference $consistency): ListUsersBody;
}
