<?php

declare(strict_types=1);

namespace Pulsar\Marketplace;

use Pulsar\Api\Api;

use function count;
use function preg_match;
use function version_compare;

/**
 * Resolves semantic version constraints for extension compatibility.
 */
#[Api(since: '1.0.0')]
final readonly class VersionConstraint
{
    private function __construct(
        public string $minVersion,
        public string $maxVersion,
        public bool $minInclusive,
        public bool $maxInclusive,
    ) {}

    /**
     * Parse a version constraint string.
     *
     * Supports:
     * - Exact: "1.2.3"
     * - Range: ">=1.0.0 <2.0.0"
     * - Caret: "^1.2.3" (>=1.2.3 <2.0.0)
     * - Tilde: "~1.2.3" (>=1.2.3 <1.3.0)
     * - Wildcard: "1.2.*" (>=1.2.0 <1.3.0)
     */
    public static function parse(string $constraint): self
    {
        $constraint = trim($constraint);

        // Caret constraint: ^1.2.3 → >=1.2.3 <2.0.0
        if (str_starts_with($constraint, '^')) {
            return self::parseCaret(substr($constraint, 1));
        }

        // Tilde constraint: ~1.2.3 → >=1.2.3 <1.3.0
        if (str_starts_with($constraint, '~')) {
            return self::parseTilde(substr($constraint, 1));
        }

        // Wildcard: 1.2.* → >=1.2.0 <1.3.0
        if (str_contains($constraint, '*')) {
            return self::parseWildcard($constraint);
        }

        // Exact version
        return new self($constraint, $constraint, true, true);
    }

    /**
     * Check if a version satisfies this constraint.
     */
    public function isSatisfiedBy(string $version): bool
    {
        if ($this->minVersion !== '') {
            $minOp = $this->minInclusive ? '>=' : '>';

            if (!version_compare($version, $this->minVersion, $minOp)) {
                return false;
            }
        }

        if ($this->maxVersion !== '') {
            $maxOp = $this->maxInclusive ? '<=' : '<';

            if (!version_compare($version, $this->maxVersion, $maxOp)) {
                return false;
            }
        }

        return true;
    }

    private static function parseCaret(string $version): self
    {
        $parts = self::splitVersion($version);

        if ($parts[0] > 0) {
            // ^1.2.3 → >=1.2.3 <2.0.0
            $max = ($parts[0] + 1) . '.0.0';
        } elseif ($parts[1] > 0) {
            // ^0.2.3 → >=0.2.3 <0.3.0
            $max = '0.' . ($parts[1] + 1) . '.0';
        } else {
            // ^0.0.3 → >=0.0.3 <0.0.4
            $max = '0.0.' . ($parts[2] + 1);
        }

        return new self($version, $max, true, false);
    }

    private static function parseTilde(string $version): self
    {
        $parts = self::splitVersion($version);

        // ~1.2.3 → >=1.2.3 <1.3.0
        $max = $parts[0] . '.' . ($parts[1] + 1) . '.0';

        return new self($version, $max, true, false);
    }

    private static function parseWildcard(string $constraint): self
    {
        $base = str_replace('.*', '', $constraint);
        $parts = array_map('intval', explode('.', $base));

        if (count($parts) === 1) {
            // 1.* → >=1.0.0 <2.0.0
            return new self(
                $parts[0] . '.0.0',
                ($parts[0] + 1) . '.0.0',
                true,
                false,
            );
        }

        // 1.2.* → >=1.2.0 <1.3.0
        return new self(
            $parts[0] . '.' . $parts[1] . '.0',
            $parts[0] . '.' . ($parts[1] + 1) . '.0',
            true,
            false,
        );
    }

    /**
     * @return array{int, int, int}
     */
    private static function splitVersion(string $version): array
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches)) {
            return [0, 0, 0];
        }

        return [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
    }
}
