<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Signing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Signing\SigningConfig;

use const DIRECTORY_SEPARATOR;

#[CoversClass(SigningConfig::class)]
final class SigningConfigTest extends TestCase
{
    #[Test]
    public function defaultsMatchFrameworkConventions(): void
    {
        $config = new SigningConfig();

        self::assertSame('dist', $config->artifactDir);
        self::assertSame('signatures.json', $config->manifestPath);
    }

    #[Test]
    public function fromArrayReadsSnakeCaseKeys(): void
    {
        $config = SigningConfig::fromArray([
            'artifact_dir' => 'release',
            'manifest_path' => 'release/sigs.json',
        ]);

        self::assertSame('release', $config->artifactDir);
        self::assertSame('release/sigs.json', $config->manifestPath);
    }

    #[Test]
    public function fromArrayFallsBackToDefaultsForMissingOrBlankValues(): void
    {
        $config = SigningConfig::fromArray([
            'artifact_dir' => '',
            'manifest_path' => ['not', 'a', 'string'],
        ]);

        self::assertSame('dist', $config->artifactDir);
        self::assertSame('signatures.json', $config->manifestPath);
    }

    #[Test]
    public function resolvedArtifactDirJoinsRelativeValueAgainstRoot(): void
    {
        $config = new SigningConfig(artifactDir: 'dist');

        self::assertSame(
            '/project' . DIRECTORY_SEPARATOR . 'dist',
            $config->resolvedArtifactDir('/project'),
        );
    }

    #[Test]
    public function resolvedManifestPathJoinsRelativeValueAgainstRoot(): void
    {
        $config = new SigningConfig(manifestPath: 'signatures.json');

        self::assertSame(
            '/project' . DIRECTORY_SEPARATOR . 'signatures.json',
            $config->resolvedManifestPath('/project'),
        );
    }

    #[Test]
    public function resolvedPathsKeepAbsoluteValues(): void
    {
        $config = new SigningConfig(
            artifactDir: '/srv/dist',
            manifestPath: 'C:\\sigs\\signatures.json',
        );

        self::assertSame('/srv/dist', $config->resolvedArtifactDir('/project'));
        self::assertSame('C:\\sigs\\signatures.json', $config->resolvedManifestPath('/project'));
    }
}
