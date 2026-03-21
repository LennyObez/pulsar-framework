<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use Pulsar\Api\Api;

/**
 * Severity classification for security incidents.
 *
 * Aligned with common incident response frameworks (NIST SP 800-61,
 * ISO 27035). Used to prioritize triage and escalation.
 * @api
 */
#[Api(since: '1.0.0')]
enum IncidentSeverity: string
{
    /** Minor issue with no immediate operational impact. */
    case Low = 'low';

    /** Notable issue requiring investigation within normal SLA. */
    case Medium = 'medium';

    /** Serious issue requiring prompt response and escalation. */
    case High = 'high';

    /** Active breach or system compromise requiring immediate response. */
    case Critical = 'critical';
}
