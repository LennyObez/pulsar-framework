<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\SbomGenerator;

#[CoversClass(SbomGenerator::class)]
final class SbomGeneratorTest extends TestCase
{
    private SbomGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new SbomGenerator();
    }

    #[Test]
    public function generateReturnsCycloneDxFormat(): void
    {
        $sbom = $this->generator->generate(null, null);

        self::assertSame('CycloneDX', $sbom['bomFormat']);
        self::assertSame('1.5', $sbom['specVersion']);
        self::assertSame(1, $sbom['version']);
        self::assertArrayHasKey('metadata', $sbom);
        self::assertArrayHasKey('components', $sbom);
    }

    #[Test]
    public function generateIncludesSerialNumber(): void
    {
        $sbom = $this->generator->generate(null, null);

        $serialNumber = $sbom['serialNumber'];
        self::assertIsString($serialNumber);
        self::assertStringStartsWith('urn:uuid:', $serialNumber);
    }

    #[Test]
    public function generateIncludesTimestamp(): void
    {
        $sbom = $this->generator->generate(null, null);

        /** @var array<string, mixed> $metadata */
        $metadata = $sbom['metadata'];

        self::assertArrayHasKey('timestamp', $metadata);
        self::assertNotEmpty($metadata['timestamp']);
    }

    #[Test]
    public function generateIncludesToolInfo(): void
    {
        $sbom = $this->generator->generate(null, null);

        /** @var array<string, mixed> $metadata */
        $metadata = $sbom['metadata'];

        /** @var list<array<string, string>> $tools */
        $tools = $metadata['tools'];

        self::assertSame('Pulsar', $tools[0]['vendor']);
        self::assertSame('pulsar-sbom-generator', $tools[0]['name']);
    }

    #[Test]
    public function generateUsesProjectInfoFromComposerJson(): void
    {
        $composerJson = [
            'name' => 'pulsar/framework',
            'version' => '1.0.0-rc.11',
        ];

        $sbom = $this->generator->generate($composerJson, null);

        /** @var array<string, mixed> $metadata */
        $metadata = $sbom['metadata'];

        /** @var array<string, string> $component */
        $component = $metadata['component'];

        self::assertSame('pulsar/framework', $component['name']);
        self::assertSame('1.0.0-rc.11', $component['version']);
        self::assertSame('framework', $component['type']);
    }

    #[Test]
    public function generateDefaultsWhenComposerJsonNull(): void
    {
        $sbom = $this->generator->generate(null, null);

        /** @var array<string, mixed> $metadata */
        $metadata = $sbom['metadata'];

        /** @var array<string, string> $component */
        $component = $metadata['component'];

        self::assertSame('unknown', $component['name']);
        self::assertSame('unknown', $component['version']);
    }

    #[Test]
    public function generateExtractsComposerPackages(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'vendor/alpha',
                    'version' => 'v1.0.0',
                    'license' => ['MIT'],
                    'authors' => [['name' => 'Alice']],
                    'dist' => ['reference' => 'abc123', 'shasum' => ''],
                ],
                [
                    'name' => 'vendor/beta',
                    'version' => 'v2.3.1',
                ],
            ],
            'packages-dev' => [
                [
                    'name' => 'vendor/test-tool',
                    'version' => 'v3.0.0',
                ],
            ],
        ];

        $sbom = $this->generator->generate(null, $lockData);

        /** @var list<array<string, mixed>> $components */
        $components = $sbom['components'];

        self::assertCount(3, $components);

        // First package — required scope, with license, supplier, hash
        $firstComponent = $components[0];
        self::assertSame('vendor/alpha', $firstComponent['name']);
        self::assertSame('v1.0.0', $firstComponent['version']);
        self::assertSame('required', $firstComponent['scope']);
        self::assertSame('pkg:composer/vendor/alpha@v1.0.0', $firstComponent['purl']);
        self::assertArrayHasKey('licenses', $firstComponent);

        /** @var list<array<string, mixed>> $licenses */
        $licenses = $firstComponent['licenses'];
        /** @var array<string, mixed> $licenseEntry */
        $licenseEntry = $licenses[0]['license'];
        self::assertSame('MIT', $licenseEntry['id']);

        /** @var array<string, mixed> $supplier */
        $supplier = $firstComponent['supplier'];
        self::assertSame('Alice', $supplier['name']);

        /** @var list<array<string, mixed>> $hashes */
        $hashes = $firstComponent['hashes'];
        self::assertSame('abc123', $hashes[0]['content']);

        // Second package — required scope, minimal
        self::assertSame('vendor/beta', $components[1]['name']);
        self::assertSame('required', $components[1]['scope']);

        // Dev package — optional scope
        self::assertSame('vendor/test-tool', $components[2]['name']);
        self::assertSame('optional', $components[2]['scope']);
    }

    #[Test]
    public function generateExtractsPnpmPackages(): void
    {
        $pnpmLock = <<<'YAML'
            lockfileVersion: '9.0'

            settings:
              autoInstallPeers: true

            packages:

              esbuild@0.27.3:
                resolution: {integrity: sha512-abc}

              '@eslint/js@9.39.2':
                resolution: {integrity: sha512-def}

            snapshots:
              something: else
            YAML;

        $sbom = $this->generator->generate(null, null, $pnpmLock);

        /** @var list<array<string, mixed>> $components */
        $components = $sbom['components'];

        self::assertCount(2, $components);

        self::assertSame('esbuild', $components[0]['name']);
        self::assertSame('0.27.3', $components[0]['version']);
        self::assertSame('pkg:npm/esbuild@0.27.3', $components[0]['purl']);
        self::assertSame('optional', $components[0]['scope']);

        self::assertSame('@eslint/js', $components[1]['name']);
        self::assertSame('9.39.2', $components[1]['version']);
    }

    #[Test]
    public function generateCombinesComposerAndPnpmPackages(): void
    {
        $lockData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0'],
            ],
        ];

        $pnpmLock = <<<'YAML'
            lockfileVersion: '9.0'

            packages:

              esbuild@0.27.3:
                resolution: {integrity: sha512-abc}
            YAML;

        $sbom = $this->generator->generate(null, $lockData, $pnpmLock);

        /** @var list<array<string, mixed>> $components */
        $components = $sbom['components'];

        self::assertCount(2, $components);
        self::assertSame('vendor/alpha', $components[0]['name']);
        self::assertSame('esbuild', $components[1]['name']);
    }

    #[Test]
    public function generateHandlesEmptyLockFiles(): void
    {
        $sbom = $this->generator->generate(
            ['name' => 'test/project', 'version' => '0.1.0'],
            ['packages' => [], 'packages-dev' => []],
            '',
        );

        /** @var list<array<string, mixed>> $components */
        $components = $sbom['components'];

        self::assertSame([], $components);
    }

    #[Test]
    public function generateSkipsInvalidComposerPackages(): void
    {
        $lockData = [
            'packages' => [
                'not-an-array',
                ['name' => 123, 'version' => 'v1.0.0'],  // non-string name
                ['name' => 'good/pkg', 'version' => 'v1.0.0'],
            ],
        ];

        $sbom = $this->generator->generate(null, $lockData);

        /** @var list<array<string, mixed>> $components */
        $components = $sbom['components'];

        self::assertCount(1, $components);
        self::assertSame('good/pkg', $components[0]['name']);
    }

    #[Test]
    public function generateHandlesPackageWithShasum(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'vendor/alpha',
                    'version' => 'v1.0.0',
                    'dist' => ['shasum' => 'deadbeef123', 'reference' => 'abc123'],
                ],
            ],
        ];

        $sbom = $this->generator->generate(null, $lockData);

        /** @var list<array<string, mixed>> $components */
        $components = $sbom['components'];

        // shasum takes priority over reference
        /** @var list<array<string, string>> $hashEntries */
        $hashEntries = $components[0]['hashes'];
        self::assertSame('SHA-1', $hashEntries[0]['alg']);
        self::assertSame('deadbeef123', $hashEntries[0]['content']);
    }

    #[Test]
    public function serialNumbersAreUnique(): void
    {
        $sbom1 = $this->generator->generate(null, null);
        $sbom2 = $this->generator->generate(null, null);

        self::assertNotSame($sbom1['serialNumber'], $sbom2['serialNumber']);
    }
}
