<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use Pulsar\Api\Api;

/**
 * Enumerates the compliance frameworks for which Pulsar provides control coverage.
 */
#[Api(since: '1.0.0')]
enum ComplianceFramework: string
{
    case Soc2 = 'soc2';
    case Hipaa = 'hipaa';
    case Gdpr = 'gdpr';
    case PciDss = 'pci_dss';
}
