<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ConfigReferenceRenderer;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Introspection\ProjectMetadataService;

use function count;
use function file_put_contents;
use function sprintf;

/**
 * Generate a Markdown reference of every configuration option (REQ5).
 *
 * Reflects the loaded *Config DTOs through the introspection schema and emits
 * one section per config class with each option's type and default, so the
 * configuration surface is documented from the source of truth rather than by
 * hand. Writes to stdout, or to a file with --output.
 *
 * Usage: config:reference [--output=docs/configuration-reference.md]
 */
#[Internal]
final class ConfigReferenceCommand extends Command
{
    private readonly ConfigReferenceRenderer $renderer;

    public function __construct(
        private readonly ProjectMetadataService $metadata,
    ) {
        parent::__construct();
        $this->renderer = new ConfigReferenceRenderer();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'config:reference';
        $this->description = 'Generate a Markdown reference of all configuration options';
        $this->addOption('output', 'Write the reference to a file instead of stdout', 'o');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $schemas = $this->metadata->snapshot()->configSchema->schemas;

        if ($schemas === []) {
            $output->writeln('No configuration schema is available.');

            return ExitCode::Success->value;
        }

        $markdown = $this->renderer->render($schemas);
        $path = $input->getNullableStringOption('output');

        if ($path === null) {
            $output->writeln($markdown);

            return ExitCode::Success->value;
        }

        if (file_put_contents($path, $markdown . "\n") === false) {
            $output->error(sprintf('Could not write the configuration reference to %s', $path));

            return ExitCode::Error->value;
        }

        $output->success(sprintf('Wrote configuration reference for %d config class(es) to %s', count($schemas), $path));

        return ExitCode::Success->value;
    }
}
