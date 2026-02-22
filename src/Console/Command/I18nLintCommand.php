<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\I18n\Linter\TranslationLinter;

use function sprintf;
use function strtoupper;

/**
 * Validates translation catalogs for common issues.
 */
final class I18nLintCommand extends Command
{
    public function __construct(
        private readonly TranslationLinter $linter,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'i18n:lint';
        $this->description = 'Validate translation catalogs';
        $this->addOption('domain', 'Translation domain to lint', '-d', 'messages');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $domain */
        $domain = $input->getOption('domain', 'messages');

        $output->info(sprintf('Linting translations for domain "%s"...', $domain));

        $result = $this->linter->lint($domain);

        if ($result->count() === 0) {
            $output->success('No issues found.');
            return ExitCode::Success->value;
        }

        foreach ($result->issues as $issue) {
            $prefix = strtoupper($issue['severity']->value);

            $output->writeln(sprintf(
                '  [%s] %s:%s (%s) — %s',
                $prefix,
                $issue['locale'],
                $issue['domain'],
                $issue['key'],
                $issue['message'],
            ));
        }

        $output->newLine();

        if ($result->hasErrors()) {
            $output->error(sprintf('Found %d issue(s), including errors.', $result->count()));
            return ExitCode::Error->value;
        }

        $output->warning(sprintf('Found %d warning(s).', $result->count()));
        return ExitCode::Success->value;
    }
}
