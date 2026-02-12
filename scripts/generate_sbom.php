<?php

declare(strict_types=1);

/**
 * Generate a CycloneDX SBOM (Software Bill of Materials) from composer.lock.
 *
 * Outputs a CycloneDX 1.5 JSON file containing all dependencies,
 * their versions, licenses, and package URLs.
 *
 * Usage:
 *   php scripts/generate_sbom.php [output-path]
 *
 * Default output: sbom.json in the project root.
 */

$projectRoot = dirname(__DIR__);
$lockFile = $projectRoot . '/composer.lock';
$outputPath = $argv[1] ?? $projectRoot . '/sbom.json';

if (!file_exists($lockFile)) {
    fwrite(STDERR, "composer.lock not found at: {$lockFile}\n");
    exit(1);
}

$lockData = json_decode(file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);

/** @var list<array<string, mixed>> $packages */
$packages = $lockData['packages'] ?? [];
/** @var list<array<string, mixed>> $devPackages */
$devPackages = $lockData['packages-dev'] ?? [];

$composerJson = json_decode(file_get_contents($projectRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

$components = [];

// Add PHP platform dependency
$phpRequirement = $composerJson['require']['php'] ?? '^8.5';
$components[] = [
    'type' => 'platform',
    'name' => 'php',
    'version' => $phpRequirement,
    'scope' => 'required',
    'description' => 'PHP runtime',
];

// Add required extensions as platform dependencies
foreach ($composerJson['require'] ?? [] as $dep => $constraint) {
    if (str_starts_with($dep, 'ext-')) {
        $components[] = [
            'type' => 'platform',
            'name' => $dep,
            'version' => $constraint,
            'scope' => 'required',
            'description' => 'PHP extension: ' . substr($dep, 4),
        ];
    }
}

foreach ($packages as $package) {
    $components[] = buildComponent($package, 'required');
}

foreach ($devPackages as $package) {
    $components[] = buildComponent($package, 'optional');
}

$sbom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'serialNumber' => 'urn:uuid:' . generateUuidV4(),
    'version' => 1,
    'metadata' => [
        'timestamp' => date('c'),
        'tools' => [
            'components' => [
                [
                    'type' => 'application',
                    'name' => 'pulsar-sbom-generator',
                    'version' => '1.0.0',
                ],
            ],
        ],
        'component' => [
            'type' => 'framework',
            'name' => 'pulsar/framework',
            'version' => getFrameworkVersion($projectRoot),
            'purl' => 'pkg:composer/pulsar/framework',
        ],
    ],
    'components' => $components,
];

$json = json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

file_put_contents($outputPath, $json . "\n");

echo "SBOM generated: {$outputPath}\n";
echo "Components: " . count($components) . " (" . count($packages) . " required, " . count($devPackages) . " dev)\n";

exit(0);

// --- Helper functions ---

/**
 * @param array<string, mixed> $package
 * @return array<string, mixed>
 */
function buildComponent(array $package, string $scope): array
{
    $name = (string) ($package['name'] ?? 'unknown');
    $version = (string) ($package['version'] ?? 'unknown');

    $component = [
        'type' => 'library',
        'name' => $name,
        'version' => $version,
        'purl' => 'pkg:composer/' . $name . '@' . $version,
        'scope' => $scope,
    ];

    // Extract licenses
    $licenses = [];
    if (isset($package['license']) && is_array($package['license'])) {
        foreach ($package['license'] as $license) {
            $licenses[] = ['license' => ['id' => (string) $license]];
        }
    }

    if ($licenses !== []) {
        $component['licenses'] = $licenses;
    }

    // Extract description
    if (isset($package['description']) && is_string($package['description'])) {
        $component['description'] = $package['description'];
    }

    // Extract hashes from dist
    if (isset($package['dist']['shasum']) && is_string($package['dist']['shasum']) && $package['dist']['shasum'] !== '') {
        $component['hashes'] = [
            ['alg' => 'SHA-1', 'content' => $package['dist']['shasum']],
        ];
    }

    return $component;
}

function getFrameworkVersion(string $projectRoot): string
{
    $versionFile = $projectRoot . '/src/Core/Version.php';

    if (!file_exists($versionFile)) {
        return 'unknown';
    }

    $content = file_get_contents($versionFile);

    if ($content === false) {
        return 'unknown';
    }

    // Parse MAJOR, MINOR, PATCH constants and optional PRERELEASE_SUFFIX
    $major = $minor = $patch = null;
    $suffix = '';

    if (preg_match('/const\s+int\s+MAJOR\s*=\s*(\d+)/', $content, $m)) {
        $major = $m[1];
    }

    if (preg_match('/const\s+int\s+MINOR\s*=\s*(\d+)/', $content, $m)) {
        $minor = $m[1];
    }

    if (preg_match('/const\s+int\s+PATCH\s*=\s*(\d+)/', $content, $m)) {
        $patch = $m[1];
    }

    if (preg_match("/const\s+string\s+PRERELEASE_SUFFIX\s*=\s*'([^']*)'/", $content, $m)) {
        $suffix = $m[1];
    }

    if ($major !== null && $minor !== null && $patch !== null) {
        return $major . '.' . $minor . '.' . $patch . $suffix;
    }

    return 'unknown';
}

function generateUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

    return sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(substr($data, 0, 4)),
        bin2hex(substr($data, 4, 2)),
        bin2hex(substr($data, 6, 2)),
        bin2hex(substr($data, 8, 2)),
        bin2hex(substr($data, 10, 6)),
    );
}
