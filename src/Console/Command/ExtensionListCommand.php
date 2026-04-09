<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionRegistry;

use function count;
use function sprintf;
use function str_contains;

/**
 * List installed extensions with status and version.
 *
 * Usage: extension:list [--filter=<pattern>]
 */
#[Internal]
final class ExtensionListCommand extends Command
{
    public function __construct(
        private readonly ExtensionRegistry $registry,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'extension:list';
        $this->description = 'List installed extensions with status and version';
        $this->addOption('filter', 'Filter extensions by name pattern', 'f');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getNullableStringOption('filter');
        $extensions = $this->registry->all();

        if ($extensions === []) {
            $output->writeln('No extensions installed.');
            return ExitCode::Success->value;
        }

        $table = new TableFormatter();
        $table->setHeaders(['Name', 'Version', 'Status', 'Providers']);

        $count = 0;

        foreach ($extensions as $extension) {
            $name = $extension->name();

            if ($filter !== null && !str_contains($name, $filter)) {
                continue;
            }

            $version = '-';

            try {
                $manifest = $this->registry->getManifest($name);
                $version = $manifest->version;
            } catch (ExtensionException) {
                // Manifest unavailable
            }

            $state = '-';

            try {
                $state = $this->registry->getState($name)->value;
            } catch (ExtensionException) {
                // State unavailable
            }

            $table->addRow([
                $name,
                $version,
                $state,
                (string) count($extension->providers()),
            ]);
            ++$count;
        }

        if ($count === 0) {
            $output->writeln('No extensions match the filter.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Extensions (%d):', $count));
        $output->newLine();
        $table->render($output);

        return ExitCode::Success->value;
    }
}
