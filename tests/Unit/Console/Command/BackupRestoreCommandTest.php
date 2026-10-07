<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\BackupRestoreCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Resilience\Backup\ArchiveFormat;
use Pulsar\Resilience\Backup\ArchiveSeal;
use Pulsar\Resilience\Backup\BackupEntry;
use Pulsar\Resilience\Backup\BackupPlan;
use Pulsar\Resilience\Backup\BackupSourceInterface;
use Pulsar\Resilience\Backup\FileTreeRestoreTarget;
use Pulsar\Resilience\Backup\RestoreTargetInterface;
use Pulsar\Resilience\Backup\SealedArchiveBackupService;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_repeat;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * A live restore proves the archive before it writes into the running deployment.
 *
 * THE CASE THIS FIXES. `restore()` is a stream: it authenticates each chunk before
 * handing on its plaintext, so no unauthenticated byte reaches a target -- but an
 * archive that stops short at entry seven has already put entries one to six into
 * the deployment by the time it refuses. During a recovery, that is a live estate
 * half-overwritten from a copy that turned out to be truncated, discovered at the
 * worst possible moment. The command reads the archive through once first.
 *
 * A drill writes nothing, so it pays nothing for the extra read.
 */
#[CoversClass(BackupRestoreCommand::class)]
final class BackupRestoreCommandTest extends TestCase
{
    private string $directory;

    private string $archive;

    private SealedArchiveBackupService $backups;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pulsar-restore-', true);

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o750, true);
        }

        $this->archive = $this->directory . DIRECTORY_SEPARATOR . 'archive.pulsarbk';
        $this->backups = new SealedArchiveBackupService(
            new ArchiveSeal(MasterKey::fromHex(bin2hex(random_bytes(32)))),
        );

        // Several entries, and the first one small: a truncation late in the archive
        // is exactly the case where a streaming restore has already written the
        // early entries before it discovers the file is a prefix.
        $this->backups->backUp($this->archive, [
            self::source('files', [
                'first.txt' => 'the first entry',
                'bulk.bin' => str_repeat('x', ArchiveFormat::CHUNK_SIZE * 3),
                'last.txt' => 'the last entry',
            ]),
        ]);
    }

    #[Override]
    protected function tearDown(): void
    {
        self::removeTree($this->directory);
    }

    #[Test]
    public function aTruncatedArchiveWritesNothingIntoTheLiveDeployment(): void
    {
        $bytes = (string) file_get_contents($this->archive);
        file_put_contents($this->archive, substr($bytes, 0, strlen($bytes) - 4_096));

        $root = $this->directory . DIRECTORY_SEPARATOR . 'live';
        $output = new BufferedOutput();

        $exit = $this->command(new FileTreeRestoreTarget('files', $root))->execute(
            new ArrayInput(arguments: [$this->archive], options: ['into' => 'live']),
            $output,
        );

        self::assertFalse(
            is_dir($root),
            'the early entries of a truncated archive must not reach the running deployment',
        );
        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('nothing was written', $output->errorBuffer);
    }

    #[Test]
    public function anIntactArchiveIsCheckedAndThenRestored(): void
    {
        $root = $this->directory . DIRECTORY_SEPARATOR . 'live';
        $output = new BufferedOutput();

        $exit = $this->command(new FileTreeRestoreTarget('files', $root))->execute(
            new ArrayInput(arguments: [$this->archive], options: ['into' => 'live']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('read back and re-digested before anything was written', $output->buffer);
        self::assertSame('the first entry', file_get_contents($root . DIRECTORY_SEPARATOR . 'first.txt'));
        self::assertSame('the last entry', file_get_contents($root . DIRECTORY_SEPARATOR . 'last.txt'));
    }

    #[Test]
    public function aDrillReadsTheArchiveBackAndWritesNothing(): void
    {
        $output = new BufferedOutput();

        $exit = $this->command()->execute(new ArrayInput(arguments: [$this->archive]), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('nothing was written', $output->buffer);
        self::assertStringNotContainsString(
            'read back and re-digested before anything was written',
            $output->buffer,
            'a drill writes nothing, so it must not pay for a second read of the archive',
        );
    }

    #[Test]
    public function anUnnamedArchiveIsRefused(): void
    {
        $output = new BufferedOutput();

        self::assertSame(
            ExitCode::Error->value,
            $this->command()->execute(new ArrayInput(arguments: []), $output),
        );
        self::assertStringContainsString('Name the archive to restore', $output->errorBuffer);
    }

    private function command(?RestoreTargetInterface $live = null): BackupRestoreCommand
    {
        return new BackupRestoreCommand(
            $this->backups,
            new BackupPlan([], $live === null ? [] : [$live]),
        );
    }

    /**
     * @param non-empty-string      $id
     * @param array<string, string> $entries
     */
    private static function source(string $id, array $entries): BackupSourceInterface
    {
        return new class ($id, $entries) implements BackupSourceInterface {
            /**
             * @param non-empty-string      $sourceId
             * @param array<string, string> $entries
             */
            public function __construct(
                private readonly string $sourceId,
                private readonly array $entries,
            ) {}

            public function id(): string
            {
                return $this->sourceId;
            }

            public function describe(): string
            {
                return 'a synthetic source';
            }

            public function entries(): iterable
            {
                foreach ($this->entries as $name => $content) {
                    if ($name === '') {
                        continue;
                    }

                    yield new BackupEntry($name, [$content]);
                }
            }
        };
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($child)) {
                self::removeTree($child);

                continue;
            }

            if (is_file($child)) {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
