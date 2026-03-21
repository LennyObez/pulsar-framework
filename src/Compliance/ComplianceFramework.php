<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use Pulsar\Api\Api;

/**
 * Enumerates the compliance frameworks for which Pulsar provides control coverage.
 * @api
 */
#[Api(since: '1.0.0')]
enum ComplianceFramework: string
{
    case Soc2 = 'soc2';
    case Hipaa = 'hipaa';
    case Gdpr = 'gdpr';
    case PciDss = 'pci_dss';
    case Nis2 = 'nis2';
    case Iso27001 = 'iso27001';
    case Psd2 = 'psd2';
    case Eidas = 'eidas';
    case Iso42001 = 'iso42001';
    case Hl7Fhir = 'hl7_fhir';
    case Mdr = 'mdr';
    case Iso13485 = 'iso13485';
    case Dora = 'dora';
    case SwiftCsp = 'swift_csp';
    case Ccpa = 'ccpa';
    case NistCsf = 'nist_csf';
    case Dsa = 'dsa';
    case DataAct = 'data_act';
}
