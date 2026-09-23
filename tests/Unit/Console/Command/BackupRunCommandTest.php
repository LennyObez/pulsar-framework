<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\BackupRunCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Resilience\Backup\ArchiveSeal;
use Pulsar\Resilience\Backup\BackupDestination;
use Pulsar\Resilience\Backup\BackupEntry;
use Pulsar\Resilience\Backup\BackupPlan;
use Pulsar\Resilience\Backup\BackupSourceInterface;
use Pulsar\Resilience\Backup\SealedArchiveBackupService;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * `backup:run` prints what is in the archive, and refuses what it cannot place.
 *
 * The command is the operator's whole view of the backup subsystem, and it shipped
 * untested. The two things it has to get right are stated here: it prints the
 * MANIFEST rather than a tick, because the entry list is the only artefact that
 * answers whether the audit trail is in the file; and it warns -- or fails, when
 * asked to -- when the archive carries no audit trail.
 */
#[CoversClass(BackupRunCommand::class)]
#[CoversClass(BackupDestination::class)]
#[CoversClass(BackupPlan::class)]
final class BackupRunCommandTest extends TestCase
{
    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pulsar-backup-run-', true);

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o750, true);
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->directory) && scandir($this->directory) !== false) {
            rmdir($this->directory);
        }
    }

    #[Test]
    public function anEmptyToOptionIsRefusedRatherThanTreatedAsAbsent(): void
    {
        // `--to=` is not the same as no --to. Falling back to the configured
        // destination would write the archive somewhere the operator did not name,
        // and passing the empty string through produced "Backup destination ""
        // cannot be written: fopen() refused the path", which names neither the
        // flag nor the mistake.
        $output = new BufferedOutput();

        $exit = $this->command()->execute(
            new ArrayInput(arguments: [], options: ['to' => '']),
            $output,
        );

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('--to was given without a path', $output->errorBuffer);
        self::assertSame([], glob($this->directory . DIRECTORY_SEPARATOR . '*'));
    }

    #[Test]
    public function itPrintsTheEntryListAndNotATick(): void
    {
        $output = new BufferedOutput();

        $exit = $this->command()->execute(new ArrayInput(), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('records/note.txt', $output->buffer);
        self::assertStringContainsString('sealed', $output->buffer);
    }

    #[Test]
    public function anArchiveWithoutTheAuditTrailWarnsAndCanBeMadeToFail(): void
    {
        $warned = new BufferedOutput();

        self::assertSame(ExitCode::Success->value, $this->command()->execute(new ArrayInput(), $warned));
        self::assertStringContainsString('carries NO audit trail', $warned->buffer);

        $failed = new BufferedOutput();
        $exit = $this->command()->execute(
            new ArrayInput(arguments: [], options: ['require-audit' => true]),
            $failed,
        );

        self::assertSame(
            ExitCode::Error->value,
            $exit,
            'a regulated deployment asks for the audit trail, and an archive without it is not a backup it can use',
        );
    }

    #[Test]
    public function anArchiveThatCarriesTheAuditTrailPassesTheAuditRequirement(): void
    {
        $output = new BufferedOutput();

        $exit = $this->command(auditToo: true)->execute(
            new ArrayInput(arguments: [], options: ['require-audit' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString(BackupPlan::AUDIT_SOURCE_ID . '/audit.jsonl', $output->buffer);
    }

    #[Test]
    public function aPlanWithNoSourcesIsRefusedBeforeAnythingIsWritten(): void
    {
        $output = new BufferedOutput();

        $command = new BackupRunCommand(
            self::backups(),
            new BackupPlan([], []),
            new BackupDestination($this->directory),
        );

        self::assertSame(ExitCode::Error->value, $command->execute(new ArrayInput(), $output));
        self::assertStringContainsString('no sources', $output->errorBuffer);
        self::assertSame([], glob($this->directory . DIRECTORY_SEPARATOR . '*'));
    }

    private function command(bool $auditToo = false): BackupRunCommand
    {
        $sources = [self::source('records', 'note.txt', 'a note')];

        if ($auditToo) {
            $sources[] = self::source(BackupPlan::AUDIT_SOURCE_ID, 'audit.jsonl', '{"event":"one"}');
        }

        return new BackupRunCommand(
            self::backups(),
            new BackupPlan($sources, []),
            new BackupDestination($this->directory),
        );
    }

    private static function backups(): SealedArchiveBackupService
    {
        return new SealedArchiveBackupService(new ArchiveSeal(MasterKey::fromHex(bin2hex(random_bytes(32)))));
    }

    /**
     * @param non-empty-string $id
     * @param non-empty-string $entry
     */
    private static function source(string $id, string $entry, string $content): BackupSourceInterface
    {
        return new class ($id, $entry, $content) implements BackupSourceInterface {
            /**
             * @param non-empty-string $sourceId
             * @param non-empty-string $entryName
             */
            public function __construct(
                private readonly string $sourceId,
                private readonly string $entryName,
                private readonly string $content,
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
                yield new BackupEntry($this->entryName, [$this->content]);
            }
        };
    }
}
