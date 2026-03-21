<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Field\Regulated\ConsentEvidence;

/**
 * Port for persisting consent evidence.
 *
 * Consent evidence is stored separately from form submission data
 * to support audit and compliance requirements.
 * @api
 */
#[Api(since: '1.0.0')]
interface ConsentStoreInterface
{
    /**
     * Persist a consent evidence record.
     */
    public function store(ConsentEvidence $evidence): void;
}
