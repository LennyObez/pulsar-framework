<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\License;

use Pulsar\Api\Api;

/**
 * Configuration for the license allowlist used by LicenseChecker.
 *
 * Defaults to common permissive and copyleft OSS licenses that are
 * broadly compatible with framework distribution. Override via
 * config/supply-chain.php to match project-specific requirements.
 */
#[Api(since: '1.0.0')]
final readonly class AllowedLicensesConfig
{
    /** @var list<string> SPDX license identifiers */
    public array $allowedLicenses;

    /**
     * @param list<string>|null $allowedLicenses Override the default allowlist
     */
    public function __construct(?array $allowedLicenses = null)
    {
        $this->allowedLicenses = $allowedLicenses ?? [
            'MIT',
            'Apache-2.0',
            'BSD-2-Clause',
            'BSD-3-Clause',
            'ISC',
            'LGPL-2.1-only',
            'LGPL-3.0-only',
            'GPL-2.0-only',
            'GPL-3.0-only',
        ];
    }
}
