<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\FileHandler;

use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(FileHandler::class)]
final class FileHandlerTest extends TestCase
{
    private string $tempDir;

    private FileHandler $handler;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_session_test_' . uniqid('', true);
        $this->handler = new FileHandler();
        $this->handler->open($this->tempDir, 'TEST_SESSION');
    }

    protected function tearDown(): void
    {
        // Clean up all session files
        $files = glob($this->tempDir . '/sess_*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        // Clean up temp files
        $tmpFiles = glob($this->tempDir . '/sess_tmp_*');
        if ($tmpFiles !== false) {
            foreach ($tmpFiles as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function openCreatesDirectory(): void
    {
        self::assertTrue(is_dir($this->tempDir));
    }

    #[Test]
    public function readReturnsEmptyStringForNonexistentSession(): void
    {
        self::assertSame('', $this->handler->read('nonexistent-id'));
    }

    #[Test]
    public function writeAndReadRoundtrip(): void
    {
        $data = 'serialized_session_data';

        self::assertTrue($this->handler->write('session-1', $data));
        self::assertSame($data, $this->handler->read('session-1'));
    }

    #[Test]
    public function destroyRemovesSessionData(): void
    {
        $this->handler->write('session-1', 'data');

        self::assertTrue($this->handler->destroy('session-1'));
        self::assertSame('', $this->handler->read('session-1'));
    }

    #[Test]
    public function destroyNonexistentReturnsTrue(): void
    {
        self::assertTrue($this->handler->destroy('nonexistent'));
    }

    #[Test]
    public function gcRemovesExpiredFiles(): void
    {
        $this->handler->write('old-session', 'old-data');

        // Touch the file to make it appear old
        $file = $this->tempDir . '/sess_old-session';
        touch($file, time() - 7200);

        $deleted = $this->handler->gc(3600);

        self::assertSame(1, $deleted);
        self::assertSame('', $this->handler->read('old-session'));
    }

    #[Test]
    public function gcPreservesActiveFiles(): void
    {
        $this->handler->write('active-session', 'active-data');

        $deleted = $this->handler->gc(3600);

        self::assertSame(0, $deleted);
        self::assertSame('active-data', $this->handler->read('active-session'));
    }

    #[Test]
    public function closeReturnsTrue(): void
    {
        self::assertTrue($this->handler->close());
    }

    #[Test]
    public function supportsConcurrencyControlReturnsFalse(): void
    {
        self::assertFalse($this->handler->supportsConcurrencyControl());
    }

    #[Test]
    public function supportsSessionListingReturnsFalse(): void
    {
        self::assertFalse($this->handler->supportsSessionListing());
    }

    #[Test]
    public function supportsRevocationReturnsFalse(): void
    {
        self::assertFalse($this->handler->supportsRevocation());
    }

    #[Test]
    public function listSessionsThrowsNotSupported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session listing');

        $this->handler->listSessions('user-1');
    }

    #[Test]
    public function revokeSessionThrowsNotSupported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session revocation');

        $this->handler->revokeSession('session-1');
    }

    #[Test]
    public function getActiveSessionsThrowsNotSupported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support concurrency control');

        $this->handler->getActiveSessions('user-1');
    }

    #[Test]
    public function overwriteExistingSession(): void
    {
        $this->handler->write('session-1', 'original');
        $this->handler->write('session-1', 'updated');

        self::assertSame('updated', $this->handler->read('session-1'));
    }

    #[Test]
    public function readWriteDestroyLifecycle(): void
    {
        // Read non-existent session
        self::assertSame('', $this->handler->read('nonexistent'));

        // Write
        self::assertTrue($this->handler->write('test-id', 'session data'));

        // Read back
        self::assertSame('session data', $this->handler->read('test-id'));

        // Destroy
        self::assertTrue($this->handler->destroy('test-id'));
        self::assertSame('', $this->handler->read('test-id'));
    }

    #[Test]
    public function gcReturnsZeroWhenNoExpiredFiles(): void
    {
        $this->handler->write('active', 'data');

        $deleted = $this->handler->gc(3600);

        self::assertSame(0, $deleted);
    }
}
