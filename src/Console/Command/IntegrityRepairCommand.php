<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function count;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function file_put_contents;
use function is_dir;

use JsonException;

use function mkdir;

use Pulsar\Config\IntegrityConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\ManifestBuilder;
use Pulsar\Integrity\ManifestFormat;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use SodiumException;

use function sprintf;

/**
 * Regenerate the integrity manifest from the current filesystem state.
 *
 * Usage: integrity:repair [--confirm]
 *
 * Requires the --confirm flag as a safety gate to prevent accidental
 * manifest regeneration. All repair operations are logged to the audit trail.
 */
final class IntegrityRepairCommand extends Command
{
    public function __construct(
        private readonly IntegrityConfig $config,
        private readonly AuditLogger $auditLogger,
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'integrity:repair';
        $this->description = 'Regenerate integrity manifest from current filesystem state';
        $this->addOption('confirm', 'Confirm manifest regeneration (safety gate)', 'c');
    }

    /**
     * @throws \Random\RandomException
     * @throws JsonException
     * @throws SodiumException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->hasOption('confirm')) {
            $output->warning('This command regenerates the integrity manifest from the current filesystem.');
            $output->writeln('  Any previously detected modifications will be accepted as the new baseline.');
            $output->newLine();
            $output->writeln('  To proceed, run with --confirm:');
            $output->writeln('    pulsar integrity:repair --confirm');

            return ExitCode::Invalid->value;
        }

        $output->writeln('Repairing integrity manifest...');
        $output->newLine();

        try {
            $builder = new ManifestBuilder($this->basePath);
            $manifest = $builder->build($this->config->include, $this->config->exclude);
        } catch (IntegrityException $e) {
            $this->auditLogger->log(
                event: AuditEvent::SystemEvent,
                outcome: AuditOutcome::Failure,
                actor: 'cli:integrity:repair',
                action: 'integrity.manifest.repair',
                resource: $this->config->manifestPath,
                metadata: ['error' => $e->getMessage()],
            );

            $output->error(sprintf('Repair failed: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        try {
            $json = ManifestFormat::toJson($manifest);
        } catch (JsonException $e) {
            $output->error(sprintf('Serialization failed: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        $outputPath = $this->basePath . DIRECTORY_SEPARATOR . $this->config->manifestPath;
        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $written = file_put_contents($outputPath, $json);

        if ($written === false) {
            $this->auditLogger->log(
                event: AuditEvent::SystemEvent,
                outcome: AuditOutcome::Failure,
                actor: 'cli:integrity:repair',
                action: 'integrity.manifest.repair',
                resource: $this->config->manifestPath,
                metadata: ['error' => 'failed to write manifest file'],
            );

            $output->error(sprintf('Failed to write manifest to "%s"', $outputPath));

            return ExitCode::Error->value;
        }

        $this->auditLogger->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: 'cli:integrity:repair',
            action: 'integrity.manifest.repair',
            resource: $this->config->manifestPath,
            metadata: [
                'entry_count' => count($manifest->entries),
                'algorithm' => $manifest->algorithm,
            ],
        );

        $output->writeln(sprintf('  Regenerated manifest with %d file(s)', count($manifest->entries)));
        $output->writeln(sprintf('  Written to %s', $outputPath));
        $output->newLine();
        $output->success('Integrity manifest repaired successfully.');

        return ExitCode::Success->value;
    }
}
