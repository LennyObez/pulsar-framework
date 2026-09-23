<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Resilience\Backup\BackupDestination;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\Backup\BackupVerification;

use function array_filter;
use function array_values;
use function count;
use function glob;
use function is_string;
use function rsort;
use function sprintf;

use const DATE_RFC3339;
use const DIRECTORY_SEPARATOR;

/**
 * Read an archive back and say whether it is intact.
 *
 * A BACKUP THAT HAS NEVER BEEN READ BACK IS A BELIEF, which is why this is a
 * command of its own with a non-zero exit code rather than a flag on
 * `backup:run`. It decrypts every chunk, re-frames every entry and recomputes
 * every entry digest against the key this host derives; it does not stat the file
 * and it does not trust the manifest that was printed when the archive was made.
 *
 * With no argument it verifies the NEWEST archive in the configured destination,
 * because that is the one an operator most often wants and the one most likely to
 * be relied on. `--all` verifies every archive there, which is the sweep to put
 * on a schedule.
 *
 * WHAT A PASS DOES NOT ESTABLISH is stated by the command itself, every time: an
 * intact archive is not a rehearsed recovery. Only `backup:restore` into a
 * scratch database, timed, answers the 72-hour question HIPAA §164.308(a)(7)
 * asks, and a command that let an operator believe otherwise would be worse than
 * one that did not exist.
 */
final class BackupVerifyCommand extends Command
{
    public function __construct(
        private readonly BackupServiceInterface $backups,
        private readonly BackupDestination $destination,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'backup:verify';
        $this->description = 'Read a sealed backup archive back and check its integrity';

        $this->addArgument('archive', 'Path to the archive; defaults to the newest in the destination');
        $this->addOption('all', 'Verify every archive in the configured destination');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $archives = $this->archives($input);

        if ($archives === []) {
            $output->error(sprintf(
                'No archive to verify. Looked in "%s"; run `pulsar backup:run` first.',
                $this->destination->directory,
            ));

            return ExitCode::Error->value;
        }

        $failed = 0;

        foreach ($archives as $archive) {
            $verification = $this->backups->verify($archive);

            $this->report($verification, $output);

            if (!$verification->intact()) {
                ++$failed;
            }
        }

        $output->newLine();

        if ($failed > 0) {
            $output->error(sprintf('%d of %d archive(s) did not read back.', $failed, count($archives)));

            return ExitCode::Error->value;
        }

        $output->success(sprintf('%d archive(s) read back intact.', count($archives)));
        $output->info(
            'Intact is not the same as recoverable. Prove the restore itself with '
                . '`pulsar backup:restore` into a scratch database, and time it.',
        );

        return ExitCode::Success->value;
    }

    private function report(BackupVerification $verification, OutputInterface $output): void
    {
        if (!$verification->intact()) {
            $output->error(sprintf('%s', $verification->refusal ?? 'refused'));

            return;
        }

        $output->writeln(sprintf(
            '  OK  %s — %d entries, %d bytes, taken %s under key %s',
            $verification->archivePath,
            $verification->entryCount(),
            $verification->bytesRead,
            $verification->createdAt?->format(DATE_RFC3339) ?? 'at an unrecorded time',
            $verification->keyId,
        ));
    }

    /**
     * @return list<non-empty-string>
     */
    private function archives(InputInterface $input): array
    {
        /** @var mixed $named */
        $named = $input->getArgument(0);

        if (is_string($named) && $named !== '') {
            return [$named];
        }

        $found = glob(
            $this->destination->directory . DIRECTORY_SEPARATOR . '*' . BackupDestination::EXTENSION,
        );

        if ($found === false) {
            return [];
        }

        // glob() is typed as returning bare strings, and `verify()` takes a path.
        // Filtering is what makes that true here rather than assumed.
        $paths = array_values(array_filter($found));

        if ($paths === []) {
            return [];
        }

        // Newest first: the archive names sort chronologically by construction,
        // which is the whole reason BackupDestination names them that way.
        rsort($paths);

        return $input->getBoolOption('all') ? $paths : [$paths[0]];
    }
}
