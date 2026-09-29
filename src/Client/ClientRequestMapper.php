<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Client\Request\ClientCheckRequest;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientTupleKeyWithoutCondition;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Model\CheckBody;
use Curentis\OpenFga\Model\CheckRequestTupleKey;
use Curentis\OpenFga\Model\ConsistencyPreference;
use Curentis\OpenFga\Model\RelationshipCondition;
use Curentis\OpenFga\Model\TupleKey;
use Curentis\OpenFga\Model\TupleKeyWithoutCondition;
use Curentis\OpenFga\Model\WriteBody;
use Curentis\OpenFga\Model\WriteRequestDeletes;
use Curentis\OpenFga\Model\WriteRequestWrites;

/**
 * @internal
 */
final class ClientRequestMapper
{
    public static function toCheckBody(
        ClientCheckRequest $request,
        ?string $authorizationModelId,
        ?ConsistencyPreference $consistency,
    ): CheckBody {
        $tupleKey = new CheckRequestTupleKey(
            user: $request->user,
            relation: $request->relation,
            object: $request->object,
        );

        return new CheckBody(
            tupleKey: $tupleKey,
            authorizationModelId: $authorizationModelId,
            consistency: $consistency?->value,
            context: $request->context,
            contextualTuples: $request->contextualTuples,
            trace: $request->trace,
        );
    }

    public static function toWriteBody(ClientWriteRequest $request, ?string $authorizationModelId): WriteBody
    {
        $writes = null;
        if ($request->writes !== []) {
            $tupleKeys = array_map(
                static fn(ClientTupleKey $tuple): TupleKey => new TupleKey(
                    object: $tuple->object,
                    relation: $tuple->relation,
                    user: $tuple->user,
                    condition: $tuple->conditionName !== null
                        ? new RelationshipCondition(name: $tuple->conditionName, context: $tuple->conditionContext)
                        : null,
                ),
                $request->writes,
            );
            $writes = new WriteRequestWrites(tupleKeys: $tupleKeys);
        }

        $deletes = null;
        if ($request->deletes !== []) {
            $tupleKeys = array_map(
                static fn(ClientTupleKeyWithoutCondition $tuple): TupleKeyWithoutCondition => new TupleKeyWithoutCondition(
                    object: $tuple->object,
                    relation: $tuple->relation,
                    user: $tuple->user,
                ),
                $request->deletes,
            );
            $deletes = new WriteRequestDeletes(tupleKeys: $tupleKeys);
        }

        return new WriteBody(
            authorizationModelId: $authorizationModelId,
            deletes: $deletes,
            writes: $writes,
        );
    }
}
