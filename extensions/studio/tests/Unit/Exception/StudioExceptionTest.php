<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Exception\StudioException;
use RuntimeException;

final class StudioExceptionTest extends TestCase
{
    #[Test]
    public function storageNotWritableIncludesPath(): void
    {
        $e = StudioException::storageNotWritable('/var/data/studio.db');

        self::assertStringContainsString('/var/data/studio.db', $e->getMessage());
    }

    #[Test]
    public function storeBusyIncludesAttemptCount(): void
    {
        $e = StudioException::storeBusy(5);

        self::assertStringContainsString('5', $e->getMessage());
    }

    #[Test]
    public function storeBusyPreservesPreviousException(): void
    {
        $previous = new RuntimeException('busy');
        $e = StudioException::storeBusy(3, $previous);

        self::assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function exportRequiresDecryptionKeyHasMeaningfulMessage(): void
    {
        $e = StudioException::exportRequiresDecryptionKey();

        self::assertStringContainsString('PULSAR_MASTER_KEY', $e->getMessage());
    }

    #[Test]
    public function schemaFailedIncludesReason(): void
    {
        $e = StudioException::schemaFailed('migration failed');

        self::assertStringContainsString('migration failed', $e->getMessage());
    }

    #[Test]
    public function chainBrokenIncludesLinkIndex(): void
    {
        $e = StudioException::chainBroken(42, 'hash mismatch');

        self::assertStringContainsString('42', $e->getMessage());
        self::assertStringContainsString('hash mismatch', $e->getMessage());
    }

    #[Test]
    public function notEnabledHasMessage(): void
    {
        $e = StudioException::notEnabled();

        self::assertStringContainsString('not enabled', $e->getMessage());
    }

    #[Test]
    public function serverStartFailedIncludesReason(): void
    {
        $e = StudioException::serverStartFailed('port in use');

        self::assertStringContainsString('port in use', $e->getMessage());
    }

    #[Test]
    public function accessDeniedIncludesReason(): void
    {
        $e = StudioException::accessDenied('ip not allowed');

        self::assertStringContainsString('ip not allowed', $e->getMessage());
    }

    #[Test]
    public function invalidConfigIncludesReason(): void
    {
        $e = StudioException::invalidConfig('missing key');

        self::assertStringContainsString('missing key', $e->getMessage());
    }

    #[Test]
    public function invalidArchiveIncludesReason(): void
    {
        $e = StudioException::invalidArchive('corrupted manifest');

        self::assertStringContainsString('corrupted manifest', $e->getMessage());
    }

    #[Test]
    public function invalidModuleIdIncludesModuleId(): void
    {
        $e = StudioException::invalidModuleId('BAD ID!');

        self::assertStringContainsString('BAD ID!', $e->getMessage());
    }

    #[Test]
    public function duplicateModuleIdIncludesModuleId(): void
    {
        $e = StudioException::duplicateModuleId('my-module');

        self::assertStringContainsString('my-module', $e->getMessage());
    }

    #[Test]
    public function routePrefixCollisionIncludesDetails(): void
    {
        $e = StudioException::routePrefixCollision('mod-b', '/studio/api', 'mod-a');

        self::assertStringContainsString('mod-b', $e->getMessage());
        self::assertStringContainsString('/studio/api', $e->getMessage());
        self::assertStringContainsString('mod-a', $e->getMessage());
    }

    #[Test]
    public function isRuntimeException(): void
    {
        $parents = class_parents(StudioException::class);

        self::assertContains(RuntimeException::class, $parents);
    }
}
