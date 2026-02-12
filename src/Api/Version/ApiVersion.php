<?php

declare(strict_types=1);

namespace Pulsar\Api\Version;

use NoDiscard;
use Pulsar\Api\Api;

use function ltrim;

/**
 * Value object representing a resolved API version.
 */
#[Api(since: '1.0.0')]
final readonly class ApiVersion
{
    /**
     * @param string $version The version identifier (e.g. "1", "2")
     * @param bool $deprecated Whether this version is deprecated
     */
    public function __construct(
        public string $version,
        public bool $deprecated = false,
    ) {}

    /**
     * Create from a raw version string (normalizes "v1" to "1").
     */
    #[NoDiscard]
    public static function fromString(string $raw): self
    {
        $version = ltrim($raw, 'vV');

        return new self(version: $version);
    }

    /**
     * Get the version as a prefixed string (e.g. "v1").
     */
    #[NoDiscard]
    public function prefixed(): string
    {
        return 'v' . $this->version;
    }
}
