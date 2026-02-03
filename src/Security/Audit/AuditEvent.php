<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

/**
 * Types of auditable security events.
 */
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
