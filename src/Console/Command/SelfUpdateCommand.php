<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\Version;

use function sprintf;
use function version_compare;

/**
 * Checks GitHub releases for newer versions of Pulsar, displays the
 * changelog diff, and guides the user through the update.
 *
 * This command does not perform destructive in-place binary replacement.
 * Instead, it checks for available updates via the release checker and
 * provides the Composer command to run.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class SelfUpdateCommand extends Command
{
    public function __construct(
        private readonly ReleaseCheckerInterface $releaseChecker,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'self-update';
        $this->description = 'Check for and apply Pulsar framework updates';

        $this->addOption('check', 'Only check for updates without applying', 'c');
        $this->addOption('force', 'Force update even if already on latest', 'f');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $currentVersion = Version::full();
        $output->info('Pulsar Framework Self-Update');
        $output->writeln(sprintf('  Current version: %s', $currentVersion));
        $output->writeln('');
        $output->writeln('Checking for updates...');

        $latestRelease = $this->releaseChecker->getLatestRelease();

        if ($latestRelease === null) {
            $output->error('Could not fetch release information. Check your network connection.');
            return ExitCode::Error->value;
        }

        $latestVersion = $latestRelease->version;
        $output->writeln(sprintf('  Latest version:  %s', $latestVersion));
        $output->writeln('');

        $force = $input->hasOption('force');

        if (!$force && version_compare($latestVersion, $currentVersion, '<=')) {
            $output->success('You are already on the latest version.');
            return ExitCode::Success->value;
        }

        // Show changelog
        $output->info('Changelog');
        $changelog = $latestRelease->changelog;

        if ($changelog !== '') {
            $output->writeln($changelog);
        } else {
            $output->writeln('  No changelog available for this release.');
        }

        $output->writeln('');

        $checkOnly = $input->hasOption('check');
        if ($checkOnly) {
            $output->info(sprintf('Update available: %s -> %s', $currentVersion, $latestVersion));
            $output->writeln('Run `pulsar self-update` to apply.');
            return ExitCode::Success->value;
        }

        // Perform the update via Composer
        $output->writeln(sprintf('Updating %s -> %s ...', $currentVersion, $latestVersion));

        $updateResult = $this->releaseChecker->applyUpdate($latestVersion);

        if ($updateResult->success) {
            $output->writeln('');
            $output->success(sprintf('Updated to %s successfully.', $latestVersion));

            if ($updateResult->notes !== '') {
                $output->writeln('');
                $output->info('Post-update notes');
                $output->writeln($updateResult->notes);
            }

            return ExitCode::Success->value;
        }

        $output->writeln('');
        $output->error('Update failed: ' . $updateResult->errorMessage);
        $output->writeln('');
        $output->writeln('You can update manually with:');
        $output->writeln(sprintf('  composer require pulsar/framework:%s', $latestVersion));

        return ExitCode::Error->value;
    }
}
