<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Resilience\Backup\BackupDestination;
use Pulsar\Resilience\Backup\BackupException;
use Pulsar\Resilience\Backup\BackupPlan;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\Backup\FileTreeBackupSource;

use function sprintf;

/**
 * Take one sealed backup of everything the deployment's plan covers.
 *
 * IT PRINTS THE MANIFEST, NOT A TICK. An operator who is handed "backup complete"
 * has learned nothing they can check; an operator who is handed the entry list has
 * the one artefact that answers the question a regulated deployment actually asks
 * of a backup, which is whether the audit trail is in it. The command says so in
 * as many words, and warns when it is not.
 *
 * It also prints what it SKIPPED. A file the source could not read is named rather
 * than dropped, because a silently incomplete archive is the failure this whole
 * subsystem exists to prevent.
 */
final class BackupRunCommand extends Command
{
    public function __construct(
        private readonly BackupServiceInterface $backups,
        private readonly BackupPlan $plan,
        private readonly BackupDestination $destination,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'backup:run';
        $this->description = 'Take one sealed, tamper-evident backup archive';

        $this->addOption('to', 'Write the archive to this path instead of the configured destination');
        $this->addOption(
            'require-audit',
            'Fail if the archive does not carry the audit trail (recommended in regulated deployments)',
        );
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = $input->getNullableStringOption('to');

        if ($to === '') {
            // `--to=` is not the same as no --to. Falling back to the configured
            // destination would write the archive somewhere the operator did not
            // name, and passing the empty string on produces "fopen() refused the
            // path", which names neither the flag nor the mistake.
            $output->error('--to was given without a path. Give it a file to write, or omit it.');

            return ExitCode::Error->value;
        }

        $path = $to ?? $this->destination->nextArchive();

        if ($this->plan->sources === []) {
            $output->error(
                'The backup plan has no sources, so an archive would be empty. Check '
                    . 'resilience.backup.trees and whether a database connection is configured.',
            );

            return ExitCode::Error->value;
        }

        try {
            $manifest = $this->backups->backUp($path, $this->plan->sources);
        } catch (BackupException $failure) {
            $output->error($failure->getMessage());

            return ExitCode::Error->value;
        }

        $output->writeln(sprintf('  archive   %s', $manifest->archivePath));
        $output->writeln(sprintf('  sealed    %d bytes under archive key %s', $manifest->sealedBytes, $manifest->keyId));
        $output->writeln(sprintf('  content   %d bytes across %d entries', $manifest->contentBytes(), $manifest->entryCount()));
        $output->newLine();

        foreach ($manifest->entries as $entry) {
            $output->writeln(sprintf('    %-56s %10d bytes', $entry->name, $entry->bytes));
        }

        $output->newLine();

        $skipped = $this->skipped();

        foreach ($skipped as $note) {
            $output->warning(sprintf('skipped: %s', $note));
        }

        $carriesAudit = $manifest->covers(BackupPlan::AUDIT_SOURCE_ID);

        if (!$carriesAudit) {
            $output->warning(
                'This archive carries NO audit trail. A recovery from it restores the data and '
                    . 'not the evidence. Enable observability.audit to include it.',
            );

            if ($input->getBoolOption('require-audit')) {
                return ExitCode::Error->value;
            }
        }

        $output->success(sprintf(
            'Backup written. Verify it with: pulsar backup:verify %s',
            $manifest->archivePath,
        ));

        $output->info(
            'The archive is sealed with a key derived from PULSAR_MASTER_KEY. It cannot be read '
                . 'without that key, and copying it off this host, keeping it, and proving that '
                . 'copy still restores are yours — see docs/backup.md.',
        );

        return ExitCode::Success->value;
    }

    /**
     * What the file-tree sources could not read on this run.
     *
     * Asked of the sources AFTER the run, because that is when they know. A plan
     * that reported its skips up front would be reporting a guess.
     *
     * @return list<string>
     */
    private function skipped(): array
    {
        $skipped = [];

        foreach ($this->plan->sources as $source) {
            if ($source instanceof FileTreeBackupSource) {
                foreach ($source->skipped() as $note) {
                    $skipped[] = $note;
                }
            }
        }

        return $skipped;
    }
}
