<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\Sink\FileSink;

use function dirname;
use function sprintf;

#[CoversClass(FileSink::class)]
final class FileSinkTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Per-test unique tempdir under the OS temp area; cleanup is
        // intentionally left to the OS (sys_get_temp_dir is wiped by
        // standard housekeeping) so the tests stay free of any
        // recursive-unlink pattern that triggers static-analysis
        // path-traversal warnings.
        $this->tempDir = sys_get_temp_dir() . '/pulsar_filesink_test_' . uniqid();
    }

    #[Test]
    public function writesJsonLine(): void
    {
        $path = $this->tempDir . '/test.log';
        $sink = new FileSink($path);

        $entry = LogEntry::create(LogLevel::Info, 'test message');
        $sink->write($entry);

        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        $data = json_decode(trim($contents), true);
        self::assertIsArray($data);
        self::assertSame('info', $data['level']);
        self::assertSame('test message', $data['message']);
    }

    #[Test]
    public function createsDirectory(): void
    {
        $path = $this->tempDir . '/nested/dir/test.log';
        $sink = new FileSink($path);

        $entry = LogEntry::create(LogLevel::Debug, 'mkdir test');
        $sink->write($entry);

        self::assertFileExists($path);
    }

    #[Test]
    public function appendsToExistingFile(): void
    {
        $path = $this->tempDir . '/append.log';
        $sink = new FileSink($path);

        $entry1 = LogEntry::create(LogLevel::Info, 'first');
        $entry2 = LogEntry::create(LogLevel::Warning, 'second');

        $sink->write($entry1);
        $sink->write($entry2);

        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        $lines = array_filter(explode("\n", trim($contents)));
        self::assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('first', $first['message']);
        self::assertSame('second', $second['message']);
    }

    /**
     * Log files routinely capture accidental PII / PHI /
     * credentials and must not be world-readable. The sink applies
     * `0o640` (owner rw, group r, world none) on first write.
     *
     * Skipped on Windows where `chmod` does not have full POSIX
     * semantics — operators rely on NTFS ACLs there.
     */
    #[Test]
    public function fileIsCreatedWithRestrictedPermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('chmod has no POSIX semantics on Windows');
        }

        $path = $this->tempDir . '/perms.log';
        $sink = new FileSink($path);

        $entry = LogEntry::create(LogLevel::Info, 'perm test');
        $sink->write($entry);

        $perms = fileperms($path);
        self::assertNotFalse($perms);

        // Mask off the file-type bits, keep just the permission bits.
        $mode = $perms & 0o777;
        self::assertSame(0o640, $mode, sprintf('Expected 0640, got %04o', $mode));
    }

    #[Test]
    public function directoryIsCreatedWithRestrictedPermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('chmod has no POSIX semantics on Windows');
        }

        $path = $this->tempDir . '/sub/dir/test.log';
        $sink = new FileSink($path);

        $entry = LogEntry::create(LogLevel::Info, 'dir perm test');
        $sink->write($entry);

        $perms = fileperms(dirname($path));
        self::assertNotFalse($perms);

        $mode = $perms & 0o777;
        self::assertSame(0o750, $mode, sprintf('Expected 0750, got %04o', $mode));
    }
}
