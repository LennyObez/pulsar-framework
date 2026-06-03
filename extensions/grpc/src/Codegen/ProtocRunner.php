<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Codegen;

use FilesystemIterator;
use Pulsar\Api\Internal;
use Pulsar\Extension\Grpc\Config\CodegenConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_exists;
use function is_dir;
use function is_resource;
use function is_string;
use function mkdir;
use function preg_match;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function trim;

/**
 * Wraps the protoc compiler invocation for generating PHP code from proto files.
 *
 * Validates that protoc is installed and at the expected version before
 * running code generation. Captures stdout/stderr and exit code for
 * structured error reporting.
 */
#[Internal(reason: 'Protoc process wrapper')]
final readonly class ProtocRunner
{
    public function __construct(
        private CodegenConfig $config,
    ) {}

    /**
     * Generate PHP code from a proto file using protoc.
     *
     * Invokes protoc with the PHP and gRPC PHP plugins, directing output
     * to the specified directory. Returns a CodegenResult with generated
     * file paths or error messages.
     */
    public function generate(string $protoFile, string $outputDir): CodegenResult
    {
        if (!file_exists($protoFile)) {
            return CodegenResult::failure([
                sprintf('Proto file not found: %s', $protoFile),
            ]);
        }

        $version = $this->detectVersion();

        if ($version === null) {
            return CodegenResult::failure([
                sprintf(
                    'protoc binary not found or not executable: %s',
                    $this->config->protocBinary,
                ),
            ]);
        }

        if (!is_dir($outputDir) && !mkdir($outputDir, 0o755, true)) {
            return CodegenResult::failure([
                sprintf('Failed to create output directory: %s', $outputDir),
            ], $version);
        }

        $args = $this->buildArgs($protoFile, $outputDir);
        [$exitCode, $outputText] = $this->runProcess($args);

        if ($exitCode !== 0) {
            $errors = $outputText !== '' ? [$outputText] : ['protoc exited with code ' . $exitCode];

            return CodegenResult::failure($errors, $version);
        }

        $generatedFiles = $this->findGeneratedFiles($outputDir);

        return CodegenResult::success($generatedFiles, $version);
    }

    /**
     * Detect the installed protoc version.
     */
    public function detectVersion(): ?string
    {
        [$exitCode, $output] = $this->runProcess([$this->config->protocBinary, '--version']);

        if ($exitCode !== 0 || $output === '') {
            return null;
        }

        $line = trim($output);

        if (preg_match('/libprotoc\s+(\d+\.\d+(?:\.\d+)?)/', $line, $matches) === 1) {
            return $matches[1];
        }

        return $line;
    }

    /**
     * Build the protoc argument array.
     *
     * @return list<string>
     */
    private function buildArgs(string $protoFile, string $outputDir): array
    {
        $args = [
            $this->config->protocBinary,
            sprintf('--proto_path=%s', $this->config->protoPath),
            sprintf('--php_out=%s', $outputDir),
        ];

        if ($this->config->grpcPhpPlugin !== '') {
            $args[] = sprintf('--grpc_out=%s', $outputDir);
            $args[] = sprintf('--plugin=protoc-gen-grpc=%s', $this->config->grpcPhpPlugin);
        }

        $args[] = $protoFile;

        return $args;
    }

    /**
     * Run a process using proc_open with an explicit argument array (no shell).
     *
     * @param list<string> $args
     *
     * @return array{int, string} [exit code, combined stdout+stderr output]
     */
    private function runProcess(array $args): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($args, $descriptors, $pipes);

        if (!is_resource($process)) {
            return [1, ''];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $output = trim(
            (is_string($stdout) ? $stdout : '')
            . (is_string($stderr) ? $stderr : ''),
        );

        return [$exitCode, $output];
    }

    /**
     * Find PHP files generated in the output directory.
     *
     * @return list<string>
     */
    private function findGeneratedFiles(string $outputDir): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($outputDir, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
