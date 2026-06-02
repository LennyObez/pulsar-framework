<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Manifest;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Core\Version;

use function is_string;
use function sprintf;
use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * Version constraint configuration for Pulsar framework compatibility.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PulsarVersionConfig
{
    public function __construct(
        public string $minVersion,
        public ?string $maxVersion = null,
    ) {}

    /**
     * Create from manifest array data.
     *
     * F3.12: `min_version` SHOULD be declared. The previous
     * default of `'0.0.0'` (= "any version") allowed a manifest
     * authored against rc.7 to silently keep loading on rc.10
     * after a breaking API change. For a banking framework
     * where extension compatibility is a stability contract,
     * the missing-key case is now flagged via
     * `E_USER_DEPRECATED` so the operator's deprecation
     * collector or PHP error log surfaces every manifest that
     * still relies on the implicit default. The next major
     * release will turn this into a hard
     * `InvalidArgumentException`. All shipped extension
     * manifests now declare an explicit `pulsar.min_version`,
     * so only third-party manifests should ever trip the
     * notice.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawMinVersion = $data['min_version'] ?? null;

        if (!is_string($rawMinVersion) || $rawMinVersion === '') {
            trigger_error(
                'Extension manifest is missing pulsar.min_version (F3.12). '
                . 'Declare the minimum framework version your extension targets, '
                . 'e.g. "pulsar": {"min_version": "1.0.0-rc.11"}. '
                . 'A future release will reject the missing key.',
                E_USER_DEPRECATED,
            );
            $minVersion = '0.0.0';
        } else {
            $minVersion = $rawMinVersion;
        }

        $rawMaxVersion = $data['max_version'] ?? null;
        $maxVersion = is_string($rawMaxVersion) ? $rawMaxVersion : null;

        return new self(
            minVersion: $minVersion,
            maxVersion: $maxVersion,
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
