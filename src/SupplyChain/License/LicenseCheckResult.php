<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\License;

use Pulsar\Api\Api;

/**
 * Result of a dependency license compliance check.
 *
 * Categorizes all packages into compliant, non-compliant, and unknown
 * based on whether their declared licenses match the configured allowlist.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LicenseCheckResult
{
    /**
     * @param list<array{name: string, version: string, license: string}> $compliantPackages
     * @param list<array{name: string, version: string, license: string}> $nonCompliantPackages
     * @param list<array{name: string, version: string}> $unknownLicensePackages
     */
    public function __construct(
        public array $compliantPackages,
        public array $nonCompliantPackages,
        public array $unknownLicensePackages,
    ) {}

    /**
     * Whether all packages have known, compliant licenses.
     */
    public function isCompliant(): bool
    {
        return $this->nonCompliantPackages === [] && $this->unknownLicensePackages === [];
    }
}
