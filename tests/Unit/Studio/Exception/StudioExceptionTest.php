<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Exception\StudioException;
use RuntimeException;

#[CoversClass(StudioException::class)]
final class StudioExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = StudioException::notEnabled();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function storageNotWritableContainsPath(): void
    {
        $exception = StudioException::storageNotWritable('/var/studio/data');

        self::assertSame('Studio storage path is not writable: /var/studio/data', $exception->getMessage());
    }

    #[Test]
    public function storeBusyContainsAttempts(): void
    {
        $exception = StudioException::storeBusy(5);

        self::assertSame('Studio storage busy after 5 attempts', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function storeBusyChainsPreviousException(): void
    {
        $previous = new RuntimeException('Lock timeout');
        $exception = StudioException::storeBusy(3, $previous);

        self::assertSame($previous, $exception->getPrevious());
        self::assertStringContainsString('3 attempts', $exception->getMessage());
    }

    #[Test]
    public function exportRequiresDecryptionKeyHasFixedMessage(): void
    {
        $exception = StudioException::exportRequiresDecryptionKey();

        self::assertStringContainsString('PULSAR_MASTER_KEY', $exception->getMessage());
        self::assertStringContainsString('Cannot export', $exception->getMessage());
        self::assertStringContainsString('chain verification', $exception->getMessage());
    }

    #[Test]
    public function schemaFailedContainsReason(): void
    {
        $exception = StudioException::schemaFailed('table creation failed');

        self::assertSame('Studio schema error: table creation failed', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function schemaFailedChainsPreviousException(): void
    {
        $previous = new RuntimeException('SQLite error');
        $exception = StudioException::schemaFailed('migration error', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function chainBrokenContainsIndexAndReason(): void
    {
        $exception = StudioException::chainBroken(42, 'hash mismatch');

        self::assertSame('Evidence chain broken at link 42: hash mismatch', $exception->getMessage());
    }

    #[Test]
    public function chainBrokenAtZeroIndex(): void
    {
        $exception = StudioException::chainBroken(0, 'missing genesis block');

        self::assertSame('Evidence chain broken at link 0: missing genesis block', $exception->getMessage());
    }

    #[Test]
    public function notEnabledHasFixedMessage(): void
    {
        $exception = StudioException::notEnabled();

        self::assertSame('Studio is not enabled', $exception->getMessage());
    }

    #[Test]
    public function serverStartFailedContainsReason(): void
    {
        $exception = StudioException::serverStartFailed('port 8080 already in use');

        self::assertSame('Studio server failed to start: port 8080 already in use', $exception->getMessage());
    }

    #[Test]
    public function accessDeniedWithDefaultReason(): void
    {
        $exception = StudioException::accessDenied();

        self::assertSame('Studio access denied: unauthorized', $exception->getMessage());
    }

    #[Test]
    public function accessDeniedWithCustomReason(): void
    {
        $exception = StudioException::accessDenied('IP not whitelisted');

        self::assertSame('Studio access denied: IP not whitelisted', $exception->getMessage());
    }

    #[Test]
    public function invalidConfigContainsReason(): void
    {
        $exception = StudioException::invalidConfig('port must be an integer');

        self::assertSame('Invalid Studio configuration: port must be an integer', $exception->getMessage());
    }

    #[Test]
    public function invalidArchiveContainsReason(): void
    {
        $exception = StudioException::invalidArchive('checksum mismatch');

        self::assertSame('Invalid Studio archive: checksum mismatch', $exception->getMessage());
    }
}
