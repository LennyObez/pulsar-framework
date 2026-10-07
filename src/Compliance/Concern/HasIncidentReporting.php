<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Pulsar\Api\Api;

/**
 * Shared compliance concern for frameworks that mandate incident/breach reporting.
 *
 * Used by GDPR, NIS2, HIPAA, PCI-DSS, PSD2, SOC 2, and others.
 * @api
 */
#[Api(since: '1.0.0')]
interface HasIncidentReporting
{
    /**
     * Maximum hours allowed to report a breach/incident to the authority.
     *
     * The resolver picks the smallest value (tightest deadline) across
     * all enabled frameworks.
     *
     * Returns null if the framework does not specify a numeric deadline.
     */
    public function breachNotificationHours(): ?int;
}
