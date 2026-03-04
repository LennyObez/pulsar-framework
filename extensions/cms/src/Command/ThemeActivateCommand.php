<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Command;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

use function is_string;
use function sprintf;

/**
 * Activate an installed CMS theme by slug.
 *
 * Deactivates the previously active theme (if any) and sets the
 * specified theme as the current active theme.
 *
 * Usage:
 *   pulsar cms:theme:activate my-theme-slug
 */
#[Internal]
final class ThemeActivateCommand extends Command
{
    public function __construct(
        private readonly ThemeRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'cms:theme:activate';
        $this->description = 'Activate an installed CMS theme';

        $this->addArgument('slug', 'Theme slug to activate', required: true);
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument(0);

        if (!is_string($slug) || $slug === '') {
            $output->error('Missing required argument: theme slug');

            return ExitCode::Invalid->value;
        }

        $theme = $this->repository->findBySlug($slug);

        if ($theme === null) {
            $output->error(sprintf('Theme not found: %s', $slug));

            return ExitCode::Error->value;
        }

        if ($theme->isDeleted()) {
            $output->error(sprintf('Theme "%s" has been deleted and cannot be activated', $slug));

            return ExitCode::Error->value;
        }

        if ($theme->isActive) {
            $output->info(sprintf('Theme "%s" is already the active theme', $slug));

            return ExitCode::Success->value;
        }

        $now = new DateTimeImmutable();

        // Deactivate the currently active theme (if any)
        $currentActive = $this->repository->findActive();

        if ($currentActive !== null) {
            $deactivated = $currentActive->deactivate($now);
            $this->repository->save($deactivated);
        }

        // Activate the requested theme
        $activated = $theme->activate('cli', $now);
        $this->repository->save($activated);

        $output->success(sprintf(
            'Theme "%s" (v%s) is now the active theme',
            $activated->displayName,
            $activated->version,
        ));

        return ExitCode::Success->value;
    }
}
