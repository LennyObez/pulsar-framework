<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\BuildException;
use RuntimeException;

#[CoversClass(BuildException::class)]
final class BuildExceptionTest extends TestCase
{
    #[Test]
    public function artifactWriteFailedContainsPathAndReason(): void
    {
        $exception = BuildException::artifactWriteFailed('/cache/extensions.php', 'permission denied');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('/cache/extensions.php', $exception->getMessage());
        self::assertStringContainsString('permission denied', $exception->getMessage());
    }

    #[Test]
    public function staleBuildContainsArtifactAndReason(): void
    {
        $exception = BuildException::staleBuild('extensions.manifest.php', 'config hash changed');

        self::assertStringContainsString('extensions.manifest.php', $exception->getMessage());
        self::assertStringContainsString('config hash changed', $exception->getMessage());
        self::assertStringContainsString('stale', $exception->getMessage());
    }

    #[Test]
    public function integrityCheckFailedContainsArtifact(): void
    {
        $exception = BuildException::integrityCheckFailed('routes.compiled.php');

        self::assertStringContainsString('routes.compiled.php', $exception->getMessage());
        self::assertStringContainsString('pulsar build', $exception->getMessage());
    }

    #[Test]
    public function integrityCheckFailedMultipleContainsAllArtifacts(): void
    {
        $exception = BuildException::integrityCheckFailedMultiple(['routes.compiled.php', 'container.compiled.php']);

        self::assertStringContainsString('routes.compiled.php', $exception->getMessage());
        self::assertStringContainsString('container.compiled.php', $exception->getMessage());
        self::assertStringContainsString('2', $exception->getMessage());
    }

    #[Test]
    public function missingArtifactContainsArtifactName(): void
    {
        $exception = BuildException::missingArtifact('container.compiled.php');

        self::assertStringContainsString('container.compiled.php', $exception->getMessage());
        self::assertStringContainsString('pulsar build', $exception->getMessage());
    }

    #[Test]
    public function missingArtifactsContainsAllNames(): void
    {
        $exception = BuildException::missingArtifacts(['events_map.php', 'i18n_catalog_index.php']);

        self::assertStringContainsString('events_map.php', $exception->getMessage());
        self::assertStringContainsString('i18n_catalog_index.php', $exception->getMessage());
    }

    #[Test]
    public function compilationFailedContainsStepAndReason(): void
    {
        $exception = BuildException::compilationFailed('extension_graph', 'circular dependency detected');

        self::assertStringContainsString('extension_graph', $exception->getMessage());
        self::assertStringContainsString('circular dependency detected', $exception->getMessage());
    }

    #[Test]
    public function signatureVerificationFailedContainsHelpfulMessage(): void
    {
        $exception = BuildException::signatureVerificationFailed();

        self::assertStringContainsString('signature verification failed', $exception->getMessage());
        self::assertStringContainsString('pulsar build --sign', $exception->getMessage());
    }

    #[Test]
    public function atomicWriteFailedContainsPaths(): void
    {
        $exception = BuildException::atomicWriteFailed('/tmp/build_abc.tmp', '/cache/manifest.php');

        self::assertStringContainsString('/tmp/build_abc.tmp', $exception->getMessage());
        self::assertStringContainsString('/cache/manifest.php', $exception->getMessage());
    }
}
