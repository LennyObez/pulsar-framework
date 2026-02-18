<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Compiler\CompiledExtensionEntry;
use Pulsar\Extension\Compiler\CompiledExtensionManifest;

#[CoversClass(CompiledExtensionManifest::class)]
#[CoversClass(CompiledExtensionEntry::class)]
final class CompiledExtensionManifestTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesManifestWithAllFields(): void
    {
        $data = [
            'extensions' => [
                [
                    'name' => 'vendor/ext-a',
                    'version' => '1.0.0',
                    'extensionClass' => 'Vendor\\ExtA\\Extension',
                    'enabled' => true,
                    'dependencies' => [],
                    'trustTier' => 'community',
                ],
            ],
            'configHashes' => ['vendor/ext-a' => 'config_hash_a'],
            'codeHashes' => ['vendor/ext-a' => 'code_hash_a'],
            'totalHash' => 'total_hash_abc',
        ];

        $manifest = CompiledExtensionManifest::fromArray($data);

        self::assertCount(1, $manifest->extensions);
        self::assertSame('vendor/ext-a', $manifest->extensions[0]->name);
        self::assertSame(['vendor/ext-a' => 'config_hash_a'], $manifest->configHashes);
        self::assertSame(['vendor/ext-a' => 'code_hash_a'], $manifest->codeHashes);
        self::assertSame('total_hash_abc', $manifest->totalHash);
    }

    #[Test]
    public function fromArrayToArrayRoundTrip(): void
    {
        $data = [
            'extensions' => [
                [
                    'name' => 'vendor/ext-a',
                    'version' => '1.0.0',
                    'extensionClass' => 'Vendor\\ExtA\\Extension',
                    'enabled' => true,
                    'dependencies' => ['vendor/ext-b'],
                    'trustTier' => 'verified',
                ],
                [
                    'name' => 'vendor/ext-b',
                    'version' => '2.0.0',
                    'extensionClass' => 'Vendor\\ExtB\\Extension',
                    'enabled' => false,
                    'dependencies' => [],
                    'trustTier' => 'community',
                ],
            ],
            'configHashes' => [
                'vendor/ext-a' => 'ch_a',
                'vendor/ext-b' => 'ch_b',
            ],
            'codeHashes' => [
                'vendor/ext-a' => 'cdh_a',
                'vendor/ext-b' => 'cdh_b',
            ],
            'totalHash' => 'total_xyz',
        ];

        $manifest = CompiledExtensionManifest::fromArray($data);
        $exported = $manifest->toArray();

        self::assertSame($data, $exported);
    }

    #[Test]
    public function computeTotalHashIsDeterministic(): void
    {
        $configHashes = ['ext-b' => 'hash_b', 'ext-a' => 'hash_a'];
        $codeHashes = ['ext-b' => 'code_b', 'ext-a' => 'code_a'];
        $entries = [
            new CompiledExtensionEntry('vendor/ext-a', '1.0.0', 'A\\Extension', true, [], 'community'),
            new CompiledExtensionEntry('vendor/ext-b', '2.0.0', 'B\\Extension', false, [], 'verified'),
        ];

        $hash1 = CompiledExtensionManifest::computeTotalHash($configHashes, $codeHashes, $entries);
        $hash2 = CompiledExtensionManifest::computeTotalHash($configHashes, $codeHashes, $entries);

        self::assertSame($hash1, $hash2);
        self::assertSame(64, strlen($hash1)); // SHA-256 hex
    }

    #[Test]
    public function computeTotalHashChangesWhenInputChanges(): void
    {
        $entries = [
            new CompiledExtensionEntry('vendor/ext-a', '1.0.0', 'A\\Extension', true, [], 'community'),
        ];

        $hash1 = CompiledExtensionManifest::computeTotalHash(
            ['vendor/ext-a' => 'config_v1'],
            ['vendor/ext-a' => 'code_v1'],
            $entries,
        );

        $hash2 = CompiledExtensionManifest::computeTotalHash(
            ['vendor/ext-a' => 'config_v2'],
            ['vendor/ext-a' => 'code_v1'],
            $entries,
        );

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function fromArrayDefaultsMissingFields(): void
    {
        $manifest = CompiledExtensionManifest::fromArray([]);

        self::assertSame([], $manifest->extensions);
        self::assertSame([], $manifest->configHashes);
        self::assertSame([], $manifest->codeHashes);
        self::assertSame('', $manifest->totalHash);
    }
}
