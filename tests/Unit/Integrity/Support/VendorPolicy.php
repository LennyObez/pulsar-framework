<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use function array_keys;
use function file_get_contents;
use function in_array;
use function json_decode;
use function sprintf;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

/**
 * The rule behind ArchitectureRulesTest::core_has_no_forbidden_vendor_dependencies,
 * pointed at a manifest rather than at this one.
 *
 * A vendor SDK in the framework's own `require` block is lock-in the whole extension
 * system exists to avoid: every application that installs Pulsar installs that SDK, its
 * transitive tree and its release cadence, whether or not it uses the service. The rule
 * is short, and short rules are the ones nobody thinks to test — which is how it came to
 * be that no one had ever seen it name a package.
 */
final readonly class VendorPolicy
{
    /**
     * @param list<string> $allowlist       Packages permitted by exact name
     * @param list<string> $prefixAllowlist Package prefixes permitted wholesale
     * @param list<string> $denylist        Vendor SDK prefixes that must never appear
     */
    public function __construct(
        private array $allowlist,
        private array $prefixAllowlist,
        private array $denylist,
    ) {}

    /**
     * Production requirements that match the vendor SDK denylist.
     *
     * @return list<string>
     */
    public function forbiddenRequirements(string $composerPath): array
    {
        /** @var array{require?: array<string, string>} $composer */
        $composer = json_decode(
            (string) file_get_contents($composerPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        /** @var list<string> $require */
        $require = array_keys($composer['require'] ?? []);
        $forbidden = [];

        foreach ($require as $package) {
            if (in_array($package, $this->allowlist, true)) {
                continue;
            }

            if ($this->hasPrefix($package, $this->prefixAllowlist)) {
                continue;
            }

            foreach ($this->denylist as $pattern) {
                if (str_starts_with($package, $pattern)) {
                    $forbidden[] = sprintf('%s (matches vendor SDK denylist pattern: %s)', $package, $pattern);

                    continue 2;
                }
            }
        }

        return $forbidden;
    }

    /**
     * @param list<string> $prefixes
     */
    private function hasPrefix(string $package, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($package, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
