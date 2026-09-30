<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Model\ConsistencyPreference;
use Curentis\OpenFga\Model\ExpandBody;
use Curentis\OpenFga\Model\ListObjectsBody;
use Curentis\OpenFga\Model\ListUsersBody;

final class DefaultConsistencyBodyFactory implements ConsistencyBodyFactoryInterface
{
    #[\Override]
    public function expand(ExpandBody $body, ConsistencyPreference $consistency, ?string $authorizationModelId): ExpandBody
    {
        return new ExpandBody(
            tupleKey: $body->tupleKey,
            authorizationModelId: $body->authorizationModelId ?? $authorizationModelId,
            consistency: $consistency->value,
            contextualTuples: $body->contextualTuples,
        );
    }

    #[\Override]
    public function listObjects(ListObjectsBody $body, ConsistencyPreference $consistency): ListObjectsBody
    {
        return new ListObjectsBody(
            user: $body->user,
            relation: $body->relation,
            type: $body->type,
            contextualTuples: $body->contextualTuples,
            context: $body->context,
            consistency: $consistency->value,
        );
    }

    #[\Override]
    public function listUsers(ListUsersBody $body, ConsistencyPreference $consistency): ListUsersBody
    {
        return new ListUsersBody(
            object: $body->object,
            relation: $body->relation,
            userFilters: $body->userFilters,
            contextualTuples: $body->contextualTuples,
            context: $body->context,
            consistency: $consistency->value,
        );
    }
}
