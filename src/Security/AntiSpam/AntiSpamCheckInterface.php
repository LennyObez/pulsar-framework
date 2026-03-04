<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

/**
 * Individual anti-spam check that can be composed into a pipeline.
 *
 * Each check analyzes a submission context and returns a result
 * indicating whether the check passed, failed, or was skipped.
 */
#[Api(since: '1.0.0')]
interface AntiSpamCheckInterface
{
    /**
     * Unique name for this check (e.g., 'honeypot', 'link_density').
     */
    public function name(): string;

    /**
     * Run the check against the given submission context.
     */
    public function check(AntiSpamContext $context): AntiSpamCheckResult;
}
