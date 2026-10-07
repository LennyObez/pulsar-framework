<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\License;

use NoDiscard;
use Pulsar\Api\Api;

use function array_values;
use function is_array;

/**
 * Configuration for the license allowlist used by LicenseChecker.
 *
 * Defaults to common permissive and copyleft OSS licenses that are
 * broadly compatible with framework distribution. Override via
 * config/supply-chain.php to match project-specific requirements.
 * @api
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

    /**
     * Build from config/supply-chain.php. A missing or malformed
     * `allowed_licenses` key falls back to the default allowlist; only
     * string entries are kept.
     *
     * @param array<string, mixed> $data The raw config/supply-chain.php array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var mixed $licenses */
        $licenses = $data['allowed_licenses'] ?? null;

        if (!is_array($licenses)) {
            return new self();
        }

        $filtered = array_values(array_filter($licenses, 'is_string'));

        return new self($filtered);
    }
}
