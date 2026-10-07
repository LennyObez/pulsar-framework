<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\IncompleteEvidenceException;

/**
 * Produces the frozen fact set the control probes read.
 *
 * The seam exists so that gathering happens when `compliance:report` runs and
 * never at boot. Gathering during wiring would resolve services before the
 * wirings that replace them have run — the ordering bug ADR-0041 fixed for
 * TokenStoreInterface — and the report would then measure, and truthfully
 * record, a deployment the running application is not.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface EvidenceSourceInterface
{
    /**
     * Observe the deployment. Called once per report.
     *
     * @throws IncompleteEvidenceException when a fact in the vocabulary was not produced
     */
    #[NoDiscard]
    public function gather(): ControlEvidence;
}
