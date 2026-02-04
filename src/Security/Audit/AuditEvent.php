<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use Pulsar\Api\Api;

/**
 * Types of auditable security events.
 */
#[Api(since: '1.0.0')]
enum AuditEvent: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case DataAccess = 'data_access';
    case DataModification = 'data_modification';
    case ConfigurationChange = 'configuration_change';
    case SecurityEvent = 'security_event';
    case SystemEvent = 'system_event';
}
