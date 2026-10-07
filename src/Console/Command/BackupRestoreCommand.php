<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Resilience\Backup\BackupException;
use Pulsar\Resilience\Backup\BackupPlan;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\Backup\DatabaseRestoreTarget;
use Pulsar\Resilience\Backup\FileTreeRestoreTarget;
use Pulsar\Resilience\Backup\InMemoryRestoreTarget;
use Pulsar\Resilience\Backup\RestoreTargetInterface;

use function is_string;
use function microtime;
use function sprintf;

/**
 * Put a sealed archive back.
 *
 * THE DEFAULT IS A DRILL, NOT A RECOVERY, and that inversion is the most
 * important decision in this command. `backup:restore <archive>` with no further
 * flags reads the archive back through in-memory targets: it proves the archive
 * decrypts, re-frames, digests clean and produces the entries it claims, and it
 * writes nothing. That is the operation an operator should be running on a
 * schedule, and making it the default is what makes it likely to be run — while
 * making a live restore require `--into` means nobody performs one by
 * autocompleting a command in a hurry.
 *
 * `--into=live` restores through the deployment's configured targets. It still
 * fails closed on anything already there: {@see DatabaseRestoreTarget} refuses a
 * populated table and {@see FileTreeRestoreTarget} refuses an existing file
 * unless `--replace` and `--overwrite` say otherwise.
 *
 * IT PRINTS THE ELAPSED TIME. HIPAA §164.308(a)(7) asks for restoration of
 * critical systems within 72 hours, and SOC 2 A1.3 asks that recovery procedures
 * be TESTED. Neither is answered by an archive existing; both are answered by a
 * number an operator can put in a record, so the command produces one whether it
 * drilled or recovered.
 */
final class BackupRestoreCommand extends Command
{
    public function __construct(
        private readonly BackupServiceInterface $backups,
        private readonly BackupPlan $plan,
        private readonly ?ConnectionInterface $connection = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'backup:restore';
        $this->description = 'Restore a sealed backup archive, or rehearse the restore without writing';

        $this->addArgument('archive', 'Path to the archive to restore', true);
        $this->addOption(
            'into',
            'Where to restore: "drill" (default, writes nothing) or "live" (the configured targets)',
        );
        $this->addOption('replace', 'Allow a live restore to delete the rows already in a table');
        $this->addOption('overwrite', 'Allow a live restore to overwrite files that already exist');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $archive = $input->getArgument(0);

        if (!is_string($archive) || $archive === '') {
            $output->error('Name the archive to restore: pulsar backup:restore <archive>');

            return ExitCode::Error->value;
        }

        $live = $input->getStringOption('into', 'drill') === 'live';
        $targets = $live ? $this->liveTargets($input) : [new InMemoryRestoreTarget('drill')];

        if ($live && $targets === []) {
            $output->error('The deployment has no restore targets configured, so a live restore would write nothing.');

            return ExitCode::Error->value;
        }

        $output->writeln($live
            ? '  mode      LIVE — this writes into the running deployment'
            : '  mode      drill — the archive is read back in full and nothing is written');
        $output->writeln(sprintf('  archive   %s', $archive));
        $output->newLine();

        // Timed from HERE, before the preflight below, because the number this
        // command prints is evidence about a RECOVERY and a live recovery pays for
        // the preflight read. Starting the clock after it would report a recovery
        // time the deployment cannot actually achieve.
        $started = microtime(true);

        // A LIVE restore reads the archive through once before it writes anything.
        // The service authenticates each chunk before handing on its plaintext, so
        // no unauthenticated byte ever reaches a target -- but it is a STREAM, and
        // an archive that stops short at entry seven has already put entries one to
        // six into the running deployment by the time it refuses. Discovering that
        // during a recovery is the situation this whole subsystem exists to
        // prevent, so the archive is proved whole first and the write is refused
        // before it starts. A drill writes nothing, so it pays nothing for this.
        if ($live) {
            $verification = $this->backups->verify($archive);

            if (!$verification->intact()) {
                $output->error(sprintf(
                    'The archive did not read back, so nothing was written: %s',
                    $verification->refusal ?? 'it was refused',
                ));

                return ExitCode::Error->value;
            }

            $output->writeln(sprintf(
                '  checked   %d entr(ies) read back and re-digested before anything was written',
                $verification->entryCount(),
            ));
            $output->newLine();
        }

        try {
            $report = $this->backups->restore($archive, $targets);
        } catch (BackupException $failure) {
            $output->error($failure->getMessage());

            return ExitCode::Error->value;
        }

        $elapsed = microtime(true) - $started;

        foreach ($report->restored as $entry) {
            $output->writeln(sprintf('    %-56s %10d bytes -> %s', $entry->name, $entry->bytes, $entry->targetId));
        }

        foreach ($report->skipped as $name) {
            $output->warning(sprintf('no target claimed "%s", so it was not restored', $name));
        }

        $output->newLine();
        $output->writeln(sprintf(
            '  %d entr(ies) restored in %.2f seconds.',
            $report->restoredCount(),
            $elapsed,
        ));

        if (!$report->complete()) {
            $output->error(
                'The archive holds entries no target claimed. A recovery that leaves part of the '
                    . 'archive behind is not a recovery; configure the missing target and re-run.',
            );

            return ExitCode::Error->value;
        }

        $output->success($live
            ? 'Restore complete. Record the elapsed time — it is the evidence a recovery objective is met.'
            : 'Drill complete: the archive read back in full and nothing was written. '
                . 'Record the elapsed time; a live restore adds write time on top of it.');

        return ExitCode::Success->value;
    }

    /**
     * The deployment's own targets, with the destructive permissions the operator
     * asked for on THIS invocation.
     *
     * Rebuilt here rather than taken from the plan, because the bound plan holds
     * the safe targets on purpose: a container that could hand out a target which
     * deletes rows is one resolve away from a command that did not mean to ask.
     *
     * @return list<RestoreTargetInterface>
     */
    private function liveTargets(InputInterface $input): array
    {
        $replace = $input->getBoolOption('replace');
        $overwrite = $input->getBoolOption('overwrite');

        $targets = [];

        foreach ($this->plan->targets as $target) {
            if ($target instanceof DatabaseRestoreTarget && $this->connection !== null) {
                $targets[] = new DatabaseRestoreTarget($this->connection, $replace);

                continue;
            }

            $targets[] = $target instanceof FileTreeRestoreTarget
                ? $target->withOverwrite($overwrite)
                : $target;
        }

        return $targets;
    }
}
