<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use Pulsar\Api\Api;
use Pulsar\Security\ThreatDetection\ThreatEvent;

/**
 * A single step in an incident response playbook.
 *
 * Each step receives the triggering threat event and returns whether
 * execution should continue to the next step.
 * @api
 */
#[Api(since: '1.0.0')]
interface PlaybookStepInterface
{
    /**
     * Execute this response step.
     *
     * @return bool True if the playbook should continue to the next step,
     *              false to halt the chain.
     */
    public function execute(ThreatEvent $event): bool;

    /**
     * Human-readable name for logging and audit trails.
     */
    public function name(): string;
}
