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
    public function expand(ExpandBody $body, ?ConsistencyPreference $consistency, ?string $authorizationModelId): ExpandBody
    {
        return new ExpandBody(
            tupleKey: $body->tupleKey,
            authorizationModelId: $body->authorizationModelId ?? $authorizationModelId,
            consistency: $consistency !== null ? $consistency->value : $body->consistency,
            contextualTuples: $body->contextualTuples,
        );
    }

    #[\Override]
    public function listObjects(ListObjectsBody $body, ?ConsistencyPreference $consistency, ?string $authorizationModelId): ListObjectsBody
    {
        return new ListObjectsBody(
            relation: $body->relation,
            type: $body->type,
            user: $body->user,
            authorizationModelId: $body->authorizationModelId ?? $authorizationModelId,
            consistency: $consistency !== null ? $consistency->value : $body->consistency,
            context: $body->context,
            contextualTuples: $body->contextualTuples,
        );
    }

    #[\Override]
    public function listUsers(ListUsersBody $body, ?ConsistencyPreference $consistency, ?string $authorizationModelId): ListUsersBody
    {
        return new ListUsersBody(
            object: $body->object,
            relation: $body->relation,
            userFilters: $body->userFilters,
            authorizationModelId: $body->authorizationModelId ?? $authorizationModelId,
            consistency: $consistency !== null ? $consistency->value : $body->consistency,
            context: $body->context,
            contextualTuples: $body->contextualTuples,
        );
    }
}
