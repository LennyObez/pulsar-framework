<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

/**
 * Orchestrates anti-spam checks in configurable order.
 *
 * Runs all registered checks against a submission context and
 * returns an aggregate result. Checks execute in registration order
 * and the pipeline short-circuits on the first hard failure when
 * configured to do so.
 */
#[Api(since: '1.0.0')]
interface AntiSpamPipelineInterface
{
    /**
     * Run all registered checks against the given context.
     */
    public function evaluate(AntiSpamContext $context): AntiSpamResult;
}
