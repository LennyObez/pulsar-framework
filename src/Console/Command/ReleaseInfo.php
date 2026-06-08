<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Pulsar\Api\Internal;

/**
 * Information about a Pulsar framework release.
 */
#[Internal]
final readonly class ReleaseInfo
{
    public function __construct(
        public string $version,
        public string $changelog,
        public string $releaseDate,
        public string $downloadUrl = '',
    ) {}
}
