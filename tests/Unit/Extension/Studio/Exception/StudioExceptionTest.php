<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Exception\StudioException;
use RuntimeException;

#[CoversClass(StudioException::class)]
final class StudioExceptionTest extends TestCase
{
    #[Test]
    public function storageNotWritableIncludesPath(): void
    {
        $e = StudioException::storageNotWritable('/var/studio/db');

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertStringContainsString('/var/studio/db', $e->getMessage());
    }

    #[Test]
    public function storeBusyIncludesAttempts(): void
    {
        $e = StudioException::storeBusy(5);

        self::assertStringContainsString('5', $e->getMessage());
    }

    #[Test]
    public function exportRequiresDecryptionKeyHasDescriptiveMessage(): void
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
    public function invalidModuleIdIncludesId(): void
    {
        $e = StudioException::invalidModuleId('BAD ID!');

        self::assertStringContainsString('BAD ID!', $e->getMessage());
        self::assertStringContainsString('[a-z0-9_-]+', $e->getMessage());
    }

    #[Test]
    public function duplicateModuleIdIncludesId(): void
    {
        $e = StudioException::duplicateModuleId('test-mod');

        self::assertStringContainsString('test-mod', $e->getMessage());
        self::assertStringContainsString('Duplicate', $e->getMessage());
    }

    #[Test]
    public function routePrefixCollisionIncludesBothModules(): void
    {
        $e = StudioException::routePrefixCollision('new-mod', '/shared', 'existing-mod');

        self::assertStringContainsString('new-mod', $e->getMessage());
        self::assertStringContainsString('/shared', $e->getMessage());
        self::assertStringContainsString('existing-mod', $e->getMessage());
    }

    #[Test]
    public function accessDeniedIncludesReason(): void
    {
        $e = StudioException::accessDenied('IP blocked');

        self::assertStringContainsString('IP blocked', $e->getMessage());
    }

    #[Test]
    public function invalidConfigIncludesReason(): void
    {
        $e = StudioException::invalidConfig('bad port');

        self::assertStringContainsString('bad port', $e->getMessage());
    }

    #[Test]
    public function invalidArchiveIncludesReason(): void
    {
        $e = StudioException::invalidArchive('corrupt zip');

        self::assertStringContainsString('corrupt zip', $e->getMessage());
    }

    #[Test]
    public function serverStartFailedIncludesReason(): void
    {
        $e = StudioException::serverStartFailed('port in use');

        self::assertStringContainsString('port in use', $e->getMessage());
    }
}
