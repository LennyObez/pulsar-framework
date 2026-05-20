<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_map;
use function array_values;
use function bin2hex;
use function chr;
use function date;
use function explode;
use function is_array;
use function is_string;
use function ord;
use function random_bytes;
use function sprintf;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Generates Software Bill of Materials in CycloneDX 1.5 JSON format.
 *
 * Required by DORA Art.28 and NIS2 Art.21(d).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SbomGenerator
{
    /**
     * Generate an SBOM from parsed lock file data.
     *
     * @param array<string, mixed>|null $composerJson Parsed composer.json
     * @param array<string, mixed>|null $composerLock Parsed composer.lock
     * @param string $pnpmLockContents Raw pnpm-lock.yaml contents (empty if none)
     * @return array<string, mixed> CycloneDX 1.5 structure
     */
    #[NoDiscard]
    public function generate(
        ?array $composerJson,
        ?array $composerLock,
        string $pnpmLockContents = '',
    ): array {
        $components = [];

        if (is_array($composerLock)) {
            $components = [
                ...$this->extractComposerComponents($composerLock, 'packages', false),
                ...$this->extractComposerComponents($composerLock, 'packages-dev', true),
            ];
        }

        if ($pnpmLockContents !== '') {
            $components = [...$components, ...$this->extractPnpmComponents($pnpmLockContents)];
        }

        $rawProjectName = is_array($composerJson) ? ($composerJson['name'] ?? null) : null;
        $projectName = is_string($rawProjectName) ? $rawProjectName : 'unknown';

        $rawProjectVersion = is_array($composerJson) ? ($composerJson['version'] ?? null) : null;
        $projectVersion = is_string($rawProjectVersion) ? $rawProjectVersion : 'unknown';

        return [
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.5',
            'serialNumber' => sprintf('urn:uuid:%s', self::generateUuidV4()),
            'version' => 1,
            'metadata' => [
                'timestamp' => date('c'),
                'tools' => [
                    [
                        'vendor' => 'Pulsar',
                        'name' => 'pulsar-sbom-generator',
                        'version' => '1.0.0',
                    ],
                ],
                'component' => [
                    'type' => 'framework',
                    'bom-ref' => $projectName,
                    'name' => $projectName,
                    'version' => $projectVersion,
                ],
            ],
            'components' => $components,
        ];
    }

    /**
     * @param array<string, mixed> $lockData
     * @return list<array<string, mixed>>
     */
    private function extractComposerComponents(array $lockData, string $key, bool $isDev): array
    {
        /** @var list<array{name?: string, version?: string, license?: list<string>, authors?: list<array{name?: string}>, dist?: array{shasum?: string}}> $packages */
        $packages = is_array($lockData[$key] ?? null) ? $lockData[$key] : [];

        $components = [];

        foreach ($packages as $package) {
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;

            if (!is_string($name) || !is_string($version)) {
                continue;
            }

            $component = [
                'type' => 'library',
                'bom-ref' => sprintf('pkg:composer/%s@%s', $name, $version),
                'name' => $name,
                'version' => $version,
                'purl' => sprintf('pkg:composer/%s@%s', $name, $version),
                'scope' => $isDev ? 'optional' : 'required',
            ];

            $licenses = $package['license'] ?? null;

            if (is_array($licenses)) {
                $stringLicenses = array_values(array_filter($licenses, 'is_string'));

                if ($stringLicenses !== []) {
                    $component['licenses'] = array_map(
                        static fn(string $id): array => ['license' => ['id' => $id]],
                        $stringLicenses,
                    );
                }
            }

            $authors = $package['authors'] ?? null;

            if (is_array($authors) && isset($authors[0]) && is_array($authors[0])) {
                $authorName = $authors[0]['name'] ?? null;

                if (is_string($authorName)) {
                    $component['supplier'] = ['name' => $authorName];
                }
            }

            $dist = $package['dist'] ?? null;

            if (is_array($dist)) {
                $shasum = $dist['shasum'] ?? null;
                $reference = $dist['reference'] ?? null;

                if (is_string($shasum) && $shasum !== '') {
                    $component['hashes'] = [['alg' => 'SHA-1', 'content' => $shasum]];
                } elseif (is_string($reference) && $reference !== '') {
                    $component['hashes'] = [['alg' => 'SHA-1', 'content' => $reference]];
                }
            }

            $components[] = $component;
        }

        return $components;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractPnpmComponents(string $contents): array
    {
        $components = [];
        $lines = explode("\n", $contents);
        $inPackages = false;

        foreach ($lines as $line) {
            if (trim($line) === 'packages:') {
                $inPackages = true;

                continue;
            }

            if ($inPackages && $line !== '' && !str_starts_with($line, ' ') && !str_starts_with($line, "\t")) {
                $inPackages = false;

                continue;
            }

            if (!$inPackages) {
                continue;
            }

            if (preg_match('/^\s{2}[\'"]?(@?[^@\'"]+)@([^\'":]+)[\'"]?:/', $line, $matches) === 1) {
                $name = $matches[1];
                $version = $matches[2];

                $components[] = [
                    'type' => 'library',
                    'bom-ref' => sprintf('pkg:npm/%s@%s', $name, $version),
                    'name' => $name,
                    'version' => $version,
                    'purl' => sprintf('pkg:npm/%s@%s', $name, $version),
                    'scope' => 'optional',
                ];
            }
        }

        return $components;
    }

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6)),
        );
    }
}
