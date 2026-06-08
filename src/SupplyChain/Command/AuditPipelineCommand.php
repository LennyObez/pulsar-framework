<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\SupplyChain\Pipeline\PipelineAuditor;

use function count;
use function sprintf;

/**
 * CLI command to audit CI/CD pipeline configurations for security compliance.
 *
 * Returns exit code 1 if the pipeline is non-compliant.
 */
#[Internal(reason: 'CLI command registration')]
final class AuditPipelineCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'supply-chain:audit-pipeline';
        $this->description = 'Audit CI/CD pipeline configurations for security compliance';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->info('Auditing CI/CD pipeline security...');

        $auditor = new PipelineAuditor();
        $result = $auditor->audit(projectRoot: $this->projectRoot);

        if ($result->presentTools !== []) {
            $output->writeln(sprintf('Present tools: %s', implode(', ', $result->presentTools)));
        }

        if ($result->missingTools !== []) {
            $output->warning(sprintf('Missing required tools: %s', implode(', ', $result->missingTools)));
        }

        foreach ($result->bypasses as $bypass) {
            $output->error(sprintf('[%s] %s: %s', $bypass['file'], $bypass['step'], $bypass['reason']));
        }

        foreach ($result->unpinnedActions as $action) {
            $output->warning(sprintf('[%s] Unpinned action: %s', $action['file'], $action['action']));
        }

        if ($result->isCompliant) {
            $output->success('Pipeline is compliant.');

            return ExitCode::Success->value;
        }

        $output->error(sprintf(
            'Pipeline is NON-COMPLIANT: %d missing tool(s), %d bypass(es), %d unpinned action(s)',
            count($result->missingTools),
            count($result->bypasses),
            count($result->unpinnedActions),
        ));

        return ExitCode::Error->value;
    }
}
