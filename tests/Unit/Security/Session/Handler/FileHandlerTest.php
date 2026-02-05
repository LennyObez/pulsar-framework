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
    public function test_open_creates_directory(): void
    {
        self::assertTrue(is_dir($this->tempDir));
    }

    #[Test]
    public function test_read_returns_empty_string_for_nonexistent_session(): void
    {
        self::assertSame('', $this->handler->read('nonexistent-id'));
    }

    #[Test]
    public function test_write_and_read_roundtrip(): void
    {
        $data = 'serialized_session_data';

        self::assertTrue($this->handler->write('session-1', $data));
        self::assertSame($data, $this->handler->read('session-1'));
    }

    #[Test]
    public function test_destroy_removes_session_data(): void
    {
        $this->handler->write('session-1', 'data');

        self::assertTrue($this->handler->destroy('session-1'));
        self::assertSame('', $this->handler->read('session-1'));
    }

    #[Test]
    public function test_destroy_nonexistent_returns_true(): void
    {
        self::assertTrue($this->handler->destroy('nonexistent'));
    }

    #[Test]
    public function test_gc_removes_expired_files(): void
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
    public function test_gc_preserves_active_files(): void
    {
        $this->handler->write('active-session', 'active-data');

        $deleted = $this->handler->gc(3600);

        self::assertSame(0, $deleted);
        self::assertSame('active-data', $this->handler->read('active-session'));
    }

    #[Test]
    public function test_close_returns_true(): void
    {
        self::assertTrue($this->handler->close());
    }

    #[Test]
    public function test_supports_concurrency_control_returns_false(): void
    {
        self::assertFalse($this->handler->supportsConcurrencyControl());
    }

    #[Test]
    public function test_supports_session_listing_returns_false(): void
    {
        self::assertFalse($this->handler->supportsSessionListing());
    }

    #[Test]
    public function test_supports_revocation_returns_false(): void
    {
        self::assertFalse($this->handler->supportsRevocation());
    }

    #[Test]
    public function test_list_sessions_throws_not_supported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session listing');

        $this->handler->listSessions('user-1');
    }

    #[Test]
    public function test_revoke_session_throws_not_supported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session revocation');

        $this->handler->revokeSession('session-1');
    }

    #[Test]
    public function test_get_active_sessions_throws_not_supported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support concurrency control');

        $this->handler->getActiveSessions('user-1');
    }

    #[Test]
    public function test_overwrite_existing_session(): void
    {
        $this->handler->write('session-1', 'original');
        $this->handler->write('session-1', 'updated');

        self::assertSame('updated', $this->handler->read('session-1'));
    }
}
