<?php

declare(strict_types=1);

namespace Pulsar\Audit;

use Pulsar\Api\Api;

/**
 * Classification of an audit actor.
 *
 * Used to disambiguate the source of an audited action so downstream tooling
 * (compliance reports, anomaly detection, retention policy) can apply
 * actor-kind-specific rules without parsing the actor identifier string.
 * @api
 */
#[Api(since: '1.0.0')]
enum AuditActorKind: string
{
    case User = 'user';
    case ServiceAccount = 'service_account';
    case System = 'system';
    case Anonymous = 'anonymous';
}
