<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Surveillance;

use Pulsar\Api\Api;

/**
 * Types of post-market surveillance reports.
 * @api
 */
#[Api(since: '1.0.0')]
enum ReportType: string
{
    /** Standard PMS report (Class I devices). */
    case PmsReport = 'pms_report';

    /** Periodic Safety Update Report (Class IIa, IIb, III devices). */
    case Psur = 'psur';

    /** Post-Market Clinical Follow-up report. */
    case Pmcf = 'pmcf';
}
