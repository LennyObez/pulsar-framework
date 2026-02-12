<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance;

use Pulsar\Api\Api;

/**
 * Classification levels for data sensitivity.
 *
 * Supports controls for data handling policies across GDPR, HIPAA, PCI-DSS, and SOX.
 */
#[Api(since: '1.0.0')]
enum DataClassification: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Restricted = 'restricted';
}
