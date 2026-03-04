<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\License;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Checks dependency licenses against a configurable allowlist.
 *
 * Reads composer.lock data to extract each package's declared licenses
 * and verifies them against the project's allowlist. Packages with no
 * declared license are categorized as "unknown."
 */
#[Api(since: '1.0.0')]
final readonly class LicenseChecker
{
    public function __construct(
        private AllowedLicensesConfig $config = new AllowedLicensesConfig(),
    ) {}

    /**
     * Check all dependency licenses against the allowlist.
     *
     * @param array<string, mixed>|null $composerLock Parsed composer.lock data.
     *                                                When null, nothing is checked.
     */
    #[NoDiscard]
    public function check(?array $composerLock): LicenseCheckResult
    {
        if ($composerLock === null) {
            return new LicenseCheckResult([], [], []);
        }

        $compliant = [];
        $nonCompliant = [];
        $unknown = [];

        $sections = ['packages', 'packages-dev'];

        foreach ($sections as $section) {
            /** @var list<array<string, mixed>> $packages */
            $packages = is_array($composerLock[$section] ?? null) ? $composerLock[$section] : [];

            foreach ($packages as $package) {
                if (!is_array($package)) {
                    continue;
                }

                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;

                if (!is_string($name) || !is_string($version)) {
                    continue;
                }

                $licenses = $package['license'] ?? null;

                if (!is_array($licenses) || $licenses === []) {
                    $unknown[] = ['name' => $name, 'version' => $version];

                    continue;
                }

                /** @var list<string> $stringLicenses */
                $stringLicenses = array_values(array_filter($licenses, 'is_string'));

                if ($stringLicenses === []) {
                    $unknown[] = ['name' => $name, 'version' => $version];

                    continue;
                }

                $allAllowed = true;

                foreach ($stringLicenses as $license) {
                    if (!in_array($license, $this->config->allowedLicenses, true)) {
                        $allAllowed = false;

                        break;
                    }
                }

                $licenseStr = implode(', ', $stringLicenses);

                if ($allAllowed) {
                    $compliant[] = ['name' => $name, 'version' => $version, 'license' => $licenseStr];
                } else {
                    $nonCompliant[] = ['name' => $name, 'version' => $version, 'license' => $licenseStr];
                }
            }
        }

        return new LicenseCheckResult(
            compliantPackages: $compliant,
            nonCompliantPackages: $nonCompliant,
            unknownLicensePackages: $unknown,
        );
    }

    /**
     * Check licenses from raw composer.lock JSON string.
     *
     * Convenience method that parses JSON before delegating to check().
     */
    #[NoDiscard]
    public function checkFromJson(string $composerLockJson): LicenseCheckResult
    {
        $trimmed = trim($composerLockJson);

        if ($trimmed === '') {
            return new LicenseCheckResult([], [], []);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);

        return $this->check($data);
    }
}
