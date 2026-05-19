<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Command;

use Override;
use Pulsar\Api\Api;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Grpc\Codegen\OutputValidator;
use Pulsar\Extension\Grpc\Codegen\ProtocRunner;
use Pulsar\Extension\Grpc\Codegen\ProtocVersionPinner;
use Pulsar\Extension\Grpc\Codegen\ServiceHandlerGenerator;
use Pulsar\Extension\Grpc\Config\CodegenConfig;

use function count;
use function glob;
use function is_string;
use function sprintf;

/**
 * Wraps protoc for code generation from proto files.
 *
 * Runs ProtocRunner to invoke protoc, validates output with OutputValidator,
 * generates Pulsar service handler base classes with ServiceHandlerGenerator,
 * and records the protoc version with ProtocVersionPinner.
 * @api
 */
#[Api(since: '1.0.0')]
final class GenerateCommand extends Command
{
    private readonly ProtocRunner $runner;
    private readonly OutputValidator $validator;
    private readonly ServiceHandlerGenerator $handlerGenerator;
    private readonly ProtocVersionPinner $versionPinner;

    public function __construct(
        private readonly CodegenConfig $config,
    ) {
        $this->runner = new ProtocRunner($this->config);
        $this->validator = new OutputValidator();
        $this->handlerGenerator = new ServiceHandlerGenerator();
        $this->versionPinner = new ProtocVersionPinner();
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'grpc:generate';
        $this->description = 'Generate PHP code from proto files using protoc';

        $this->addArgument('proto', 'Path to a specific proto file (optional, defaults to all)');
        $this->addOption('output', 'Output directory for generated code', 'o', $this->config->outputPath);
        $this->addOption('validate-only', 'Only validate without generating handler classes');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $protoFile = $input->getArgument(0);
        $outputDir = $this->resolveOutputDir($input);
        $validateOnly = $input->hasOption('validate-only');

        $protoFiles = $this->resolveProtoFiles($protoFile);

        if ($protoFiles === []) {
            $output->errorln(sprintf(
                'No proto files found in: %s',
                is_string($protoFile) ? $protoFile : $this->config->protoPath,
            ));

            return ExitCode::Error->value;
        }

        $output->info(sprintf('Found %d proto file(s) to process', count($protoFiles)));

        $allGeneratedFiles = [];
        $hasErrors = false;

        foreach ($protoFiles as $file) {
            $output->writeln(sprintf('  Processing: %s', $file));

            $result = $this->runner->generate($file, $outputDir);

            if (!$result->success) {
                $hasErrors = true;
                $output->errorln(sprintf('  Failed: %s', $file));

                foreach ($result->errors as $error) {
                    $output->errorln(sprintf('    %s', $error));
                }

                continue;
            }

            $output->writeln(sprintf(
                '  Generated %d file(s) (protoc %s)',
                count($result->generatedFiles),
                $result->protocVersion,
            ));

            $allGeneratedFiles = [...$allGeneratedFiles, ...$result->generatedFiles];
        }

        if ($hasErrors) {
            $output->errorln('Code generation completed with errors.');

            return ExitCode::Error->value;
        }

        // Validate output
        $output->info('Validating generated code...');
        $validationErrors = $this->validator->validate($allGeneratedFiles);

        if ($validationErrors !== []) {
            $output->errorln('Validation failed:');

            foreach ($validationErrors as $error) {
                $output->errorln(sprintf('  %s', $error));
            }

            return ExitCode::Error->value;
        }

        $output->writeln('  Validation passed');

        // Record protoc version
        $protocVersion = $this->runner->detectVersion();

        if ($protocVersion !== null) {
            $manifestPath = $this->config->protoPath . '/build-metadata.json';
            $pinnedVersion = $this->versionPinner->getPinnedVersion($manifestPath);

            if ($pinnedVersion !== null && !$this->versionPinner->verifyVersion($protocVersion, $manifestPath)) {
                $output->warning(sprintf(
                    'protoc version changed: pinned=%s, current=%s. Updating pin.',
                    $pinnedVersion,
                    $protocVersion,
                ));
            }

            $this->versionPinner->recordVersion($protocVersion, $manifestPath);
            $output->writeln(sprintf('  Recorded protoc version: %s', $protocVersion));
        }

        if ($validateOnly) {
            $output->success('Validation complete. Skipping handler generation (--validate-only).');

            return ExitCode::Success->value;
        }

        // Generate handler base classes
        $output->info('Generating service handler base classes...');
        $handlerNamespace = 'App\\Grpc\\Handler';

        $handlerFiles = $this->handlerGenerator->generate(
            $allGeneratedFiles,
            $outputDir . '/Handler',
            $handlerNamespace,
        );

        if ($handlerFiles !== []) {
            $output->writeln(sprintf('  Generated %d handler base class(es)', count($handlerFiles)));

            foreach ($handlerFiles as $handlerFile) {
                $output->writeln(sprintf('    %s', $handlerFile));
            }
        } else {
            $output->writeln('  No service definitions found: no handlers generated');
        }

        $totalFiles = count($allGeneratedFiles) + count($handlerFiles);
        $output->success(sprintf('Code generation complete: %d file(s) generated.', $totalFiles));

        return ExitCode::Success->value;
    }

    /**
     * Resolve proto files to process.
     *
     * @return list<string>
     */
    private function resolveProtoFiles(mixed $protoFile): array
    {
        if (is_string($protoFile) && $protoFile !== '') {
            return [$protoFile];
        }

        $pattern = $this->config->protoPath . '/**/*.proto';
        $files = glob($pattern, GLOB_BRACE) ?: [];

        // Also check non-recursive
        $topLevel = glob($this->config->protoPath . '/*.proto') ?: [];

        /** @var list<string> */
        return array_values(array_unique([...$topLevel, ...$files]));
    }

    private function resolveOutputDir(InputInterface $input): string
    {
        if ($input->hasOption('output')) {
            $value = $input->getStringOption('output');

            if ($value !== '') {
                return $value;
            }
        }

        return $this->config->outputPath;
    }
}
