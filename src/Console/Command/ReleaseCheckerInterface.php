<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Pulsar\Api\Internal;

/**
 * Contract for checking and applying framework updates.
 */
#[Internal]
interface ReleaseCheckerInterface
{
    /**
     * Fetch the latest release information from the release channel.
     */
    public function getLatestRelease(): ?ReleaseInfo;

    /**
     * Apply an update to the given version.
     */
    public function applyUpdate(string $version): UpdateResult;
}
