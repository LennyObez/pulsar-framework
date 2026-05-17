<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Incident;

use Pulsar\Api\Api;

/**
 * ICT incident classification per DORA Article 18.
 *
 * Incidents are classified as major or non-major based on RTS criteria
 * including number of clients affected, duration, data loss, criticality
 * of services affected, and geographical spread.
 * @api
 */
#[Api(since: '1.0.0')]
enum IctIncidentClassification: string
{
    case Major = 'major';
    case NonMajor = 'non_major';
}
