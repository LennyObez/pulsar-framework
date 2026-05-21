<?php

declare(strict_types=1);

namespace Pulsar\View\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\ViewException;

use function file_get_contents;
use function is_string;
use function sprintf;

/**
 * Shows the compiled output diff for a Pulse template.
 *
 * Usage: pulsar pulse:diff template.pulse.php
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'CLI command implementation')]
final class PulseDiffCommand extends Command
{
    public function __construct(
        private readonly TemplateCompiler $compiler,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'pulse:diff';
        $this->description = 'Show compiled output for a Pulse template';
        $this->addArgument('template', 'Template name (dot-notation)');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $templateNameRaw = $input->getArgument('template');

        if (!is_string($templateNameRaw) || $templateNameRaw === '') {
            $output->error('Template name is required');

            return ExitCode::Error->value;
        }

        $templateName = $templateNameRaw;

        try {
            $sourcePath = $this->compiler->resolve($templateName);
            $sourceContent = file_get_contents($sourcePath);

            if ($sourceContent === false) {
                $output->error(sprintf('Could not read source file: %s', $sourcePath));

                return ExitCode::Error->value;
            }

            $compiledOutput = $this->compiler->compileSource($sourceContent);

            $output->info(sprintf('Source: %s', $sourcePath));
            $output->info('--- Source ---');
            $output->writeln($sourceContent);
            $output->info('--- Compiled ---');
            $output->writeln($compiledOutput);

            return ExitCode::Success->value;
        } catch (ViewException $e) {
            $output->error($e->getMessage());

            return ExitCode::Error->value;
        }
    }
}
