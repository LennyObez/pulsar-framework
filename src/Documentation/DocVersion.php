<?php

declare(strict_types=1);

namespace Pulsar\Documentation;

use Pulsar\Api\Api;

/**
 * Represents a single documentation version (e.g. 1.0, 1.1).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DocVersion
{
    public function __construct(
        public string $version,
        public string $label,
        public string $basePath,
        public bool $isLatest = false,
        public bool $isPrerelease = false,
    ) {}

    public function urlPrefix(): string
    {
        return '/docs/' . $this->version;
    }
}
