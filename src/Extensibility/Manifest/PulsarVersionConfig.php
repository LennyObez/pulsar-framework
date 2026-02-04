<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Manifest;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Core\Version;

use function sprintf;

/**
 * Version constraint configuration for Pulsar framework compatibility.
 */
#[Api(since: '1.0.0')]
readonly class PulsarVersionConfig
{
    public function __construct(
        public string $minVersion,
        public ?string $maxVersion = null,
    ) {}

    /**
     * Create from manifest array data.
     *
     * @param array{min_version?: string, max_version?: string} $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            minVersion: $data['min_version'] ?? '0.0.0',
            maxVersion: $data['max_version'] ?? null,
        );
    }

    /**
     * Check if the current framework version satisfies the constraints.
     */
    public function isSatisfiedBy(string $version): bool
    {
        if (version_compare($version, $this->minVersion, '<')) {
            return false;
        }

        if ($this->maxVersion !== null && version_compare($version, $this->maxVersion, '>')) {
            return false;
        }

        return true;
    }

    /**
     * Check if satisfied by the current Pulsar version.
     */
    public function isSatisfiedByCurrent(): bool
    {
        return $this->isSatisfiedBy(Version::short());
    }

    /**
     * Get the constraint as a human-readable string.
     */
    public function toString(): string
    {
        if ($this->maxVersion !== null) {
            return sprintf('%s - %s', $this->minVersion, $this->maxVersion);
        }

        return sprintf('>= %s', $this->minVersion);
    }
}
