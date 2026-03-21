<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Incident;

use Pulsar\Api\Api;

/**
 * Incident reporting phases per DORA Article 19.
 * @api
 */
#[Api(since: '1.0.0')]
enum IncidentReportingPhase: string
{
    case Detection = 'detection';
    case InitialNotification = 'initial_notification';
    case IntermediateReport = 'intermediate_report';
    case FinalReport = 'final_report';
    case Closed = 'closed';
}
