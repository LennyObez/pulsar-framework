<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Handler;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame('', $this->handler->read('aabbccdd0011223344556677'));
    }

    #[Test]
    public function writeAndReadRoundtrip(): void
    {
        $data = 'serialized_session_data';
        $id = 'aabbccdd00112233';

        self::assertTrue($this->handler->write($id, $data));
        self::assertSame($data, $this->handler->read($id));
    }

    #[Test]
    public function destroyRemovesSessionData(): void
    {
        $id = 'aabbccdd00112233';
        $this->handler->write($id, 'data');

        self::assertTrue($this->handler->destroy($id));
        self::assertSame('', $this->handler->read($id));
    }

    #[Test]
    public function destroyNonexistentReturnsTrue(): void
    {
        self::assertTrue($this->handler->destroy('aabbccdd'));
    }

    #[Test]
    public function gcRemovesExpiredFiles(): void
    {
        $id = 'aabbccddee001122';
        $this->handler->write($id, 'old-data');

        // Touch the file to make it appear old
        $file = $this->tempDir . '/sess_' . $id;
        touch($file, time() - 7200);

        $deleted = $this->handler->gc(3600);

        self::assertSame(1, $deleted);
        self::assertSame('', $this->handler->read($id));
    }

    #[Test]
    public function gcPreservesActiveFiles(): void
    {
        $id = 'aabbccddee003344';
        $this->handler->write($id, 'active-data');

        $deleted = $this->handler->gc(3600);

        self::assertSame(0, $deleted);
        self::assertSame('active-data', $this->handler->read($id));
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

        $this->handler->listSessions('aabb0011');
    }

    #[Test]
    public function revokeSessionThrowsNotSupported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session revocation');

        $this->handler->revokeSession('aabb0011');
    }

    #[Test]
    public function getActiveSessionsThrowsNotSupported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support concurrency control');

        $this->handler->getActiveSessions('aabb0011');
    }

    #[Test]
    public function overwriteExistingSession(): void
    {
        $id = 'aabbccdd00112233';
        $this->handler->write($id, 'original');
        $this->handler->write($id, 'updated');

        self::assertSame('updated', $this->handler->read($id));
    }

    #[Test]
    public function readWriteDestroyLifecycle(): void
    {
        $id = 'aabbccdd00112233';

        // Read non-existent session
        self::assertSame('', $this->handler->read($id));

        // Write
        self::assertTrue($this->handler->write($id, 'session data'));

        // Read back
        self::assertSame('session data', $this->handler->read($id));

        // Destroy
        self::assertTrue($this->handler->destroy($id));
        self::assertSame('', $this->handler->read($id));
    }

    #[Test]
    public function gcReturnsZeroWhenNoExpiredFiles(): void
    {
        $this->handler->write('aabbccdd', 'data');

        $deleted = $this->handler->gc(3600);

        self::assertSame(0, $deleted);
    }

    // --- Session ID validation ---

    #[Test]
    public function readRejectsInvalidSessionId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid session ID format');

        $this->handler->read('../../etc/passwd');
    }

    #[Test]
    public function writeRejectsInvalidSessionId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid session ID format');

        $this->handler->write('../traversal', 'data');
    }

    #[Test]
    public function destroyRejectsInvalidSessionId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid session ID format');

        $this->handler->destroy('session; rm -rf /');
    }

    #[Test]
    #[DataProvider('invalidSessionIds')]
    public function sessionIdValidationRejectsUnsafeInput(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid session ID format');

        $this->handler->read($id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSessionIds(): iterable
    {
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'directory separator' => ['abc/def'];
        yield 'backslash' => ['abc\\def'];
        yield 'null byte' => ["abc\0def"];
        yield 'uppercase hex' => ['AABBCCDD'];
        yield 'mixed case' => ['aAbBcCdD'];
        yield 'non-hex letters' => ['ghijklmn'];
        yield 'spaces' => ['aabb ccdd'];
        yield 'empty string' => [''];
        yield 'special chars' => ['sess_../../foo'];
    }

    #[Test]
    public function validHexSessionIdIsAccepted(): void
    {
        $id = '0123456789abcdef';
        self::assertTrue($this->handler->write($id, 'valid'));
        self::assertSame('valid', $this->handler->read($id));
    }
}
