<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Hygiene;

use Pulsar\Api\Api;

/**
 * Contract for runtime hygiene profiles that reset global state between requests.
 */
#[Api(since: '1.0.0')]
interface HygieneProfileInterface
{
    /**
     * Reset all global state covered by this profile.
     *
     * Called at the START of each request in persistent runtimes,
     * before the request sandbox begins its scope.
     */
    public function apply(): void;
}
