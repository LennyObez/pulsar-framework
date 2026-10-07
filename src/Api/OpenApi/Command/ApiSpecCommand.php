<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi\Command;

use JsonException;
use Override;
use Pulsar\Api\OpenApi\EndpointScanner;
use Pulsar\Api\OpenApi\OpenApiConfig;
use Pulsar\Api\OpenApi\SchemaInferrer;
use Pulsar\Api\OpenApi\SpecGenerator;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\KernelInterface;

use function count;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Generates a versioned OpenAPI specification artifact.
 *
 * Boots the application, scans registered routes for API documentation
 * attributes, and writes the resulting OpenAPI v3.1 JSON spec to disk.
 *
 * Usage: api:spec [--output=path]
 */
final class ApiSpecCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly OpenApiConfig $config,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'api:spec';
        $this->description = 'Generate an OpenAPI v3.1 specification from registered routes';
        $this->addOption('output', 'Custom output path for the spec file', 'o');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->kernel->boot();

        $outputPath = $input->getStringOption('output', $this->config->outputPath);

        $output->writeln('Scanning routes for OpenAPI documentation...');

        $routes = $this->kernel->router()->routes();

        if ($routes === []) {
            $output->warning('No routes registered. Generated spec will have empty paths.');
        }

        $scanner = new EndpointScanner();
        $endpoints = $scanner->scan($routes);

        $output->writeln(sprintf('  Found %d endpoint(s)', count($endpoints)));

        $schemaInferrer = new SchemaInferrer();
        $generator = new SpecGenerator($this->config, $schemaInferrer);

        try {
            $json = $generator->generateJson($endpoints);
        } catch (JsonException $e) {
            $output->error(sprintf('JSON encoding failed: %s', $e->getMessage()));
            return ExitCode::Error->value;
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $written = file_put_contents($outputPath, $json);
        if ($written === false) {
            $output->error(sprintf('Failed to write spec to "%s"', $outputPath));
            return ExitCode::Error->value;
        }

        $output->newLine();
        $output->success(sprintf('OpenAPI spec written to %s (%s)', $outputPath, $this->config->version));

        return ExitCode::Success->value;
    }
}
