<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\SupplyChain\Vex\VexConfig;
use Pulsar\SupplyChain\Vex\VexGenerator;
use Pulsar\SupplyChain\Vex\VexSerializer;

use function count;
use function file_put_contents;
use function sprintf;

/**
 * CLI command to generate a VEX (Vulnerability Exploitability eXchange) document.
 *
 * Runs vulnerability analysis and produces an OpenVEX JSON file alongside the SBOM.
 */
#[Internal(reason: 'CLI command registration')]
final class VexCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly VexConfig $config = new VexConfig(),
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'supply-chain:vex';
        $this->description = 'Generate a VEX document for known vulnerabilities';

        $this->addOption('output', 'Output file path (default: vex.json)', 'o');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputPath = $input->getStringOption('output', $this->config->resolvedOutputPath($this->projectRoot));

        $output->info('Generating VEX document...');

        $generator = new VexGenerator($this->projectRoot, $this->config->sourceDir);
        $document = $generator->generate();

        $serializer = new VexSerializer();
        $json = $serializer->serialize($document);

        file_put_contents($outputPath, $json);

        $stmtCount = count($document->statements);
        $output->success(sprintf(
            'VEX document generated: %s (%d statement%s)',
            $outputPath,
            $stmtCount,
            $stmtCount === 1 ? '' : 's',
        ));

        return ExitCode::Success->value;
    }
}
