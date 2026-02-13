<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Codegen;

use Pulsar\Api\Internal;

use function fclose;
use function file_exists;
use function file_get_contents;
use function is_resource;
use function is_string;
use function preg_match;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function trim;

/**
 * Validates protoc output for correctness.
 *
 * Checks generated PHP files for naming conflicts, unresolved imports,
 * and structural issues that would prevent the generated code from loading.
 */
#[Internal(reason: 'Codegen output validation')]
final class OutputValidator
{
    /**
     * Validate generated PHP files.
     *
     * @param list<string> $generatedFiles Paths to generated PHP files
     *
     * @return list<string> List of validation errors (empty if valid)
     */
    public function validate(array $generatedFiles): array
    {
        return [
            ...$this->checkFilesExist($generatedFiles),
            ...$this->checkNamingConflicts($generatedFiles),
            ...$this->checkSyntax($generatedFiles),
        ];
    }

    /**
     * Check that all listed files actually exist on disk.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function checkFilesExist(array $files): array
    {
        $errors = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                $errors[] = sprintf('Generated file not found: %s', $file);
            }
        }

        return $errors;
    }

    /**
     * Check for fully qualified class name conflicts across generated files.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function checkNamingConflicts(array $files): array
    {
        $errors = [];

        /** @var array<string, string> $classMap FQCN => file path */
        $classMap = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $content = file_get_contents($file);

            if (!is_string($content)) {
                continue;
            }

            $fqcn = $this->extractFullyQualifiedClassName($content);

            if ($fqcn === null) {
                continue;
            }

            if (isset($classMap[$fqcn])) {
                $errors[] = sprintf(
                    'Class name conflict: "%s" is defined in both "%s" and "%s"',
                    $fqcn,
                    $classMap[$fqcn],
                    $file,
                );
            } else {
                $classMap[$fqcn] = $file;
            }
        }

        return $errors;
    }

    /**
     * Run basic PHP syntax validation on generated files using proc_open.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function checkSyntax(array $files): array
    {
        $errors = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            [$exitCode, $output] = $this->runPhpLint($file);

            if ($exitCode !== 0) {
                $errors[] = sprintf(
                    'Syntax error in generated file %s: %s',
                    $file,
                    $output,
                );
            }
        }

        return $errors;
    }

    /**
     * Run `php -l` on a file using proc_open with an argument array (no shell).
     *
     * @return array{int, string} [exit code, output]
     */
    private function runPhpLint(string $file): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open([PHP_BINARY, '-l', $file], $descriptors, $pipes);

        if (!is_resource($process)) {
            return [1, 'Failed to start PHP lint process'];
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
     * Extract the fully qualified class name from a PHP file's contents.
     */
    private function extractFullyQualifiedClassName(string $content): ?string
    {
        $namespace = null;
        $className = null;

        if (preg_match('/namespace\s+([^;\s]+)\s*;/', $content, $nsMatch) === 1) {
            $namespace = $nsMatch[1];
        }

        if (preg_match('/(?:class|interface|enum)\s+(\w+)/', $content, $classMatch) === 1) {
            $className = $classMatch[1];
        }

        if ($className === null) {
            return null;
        }

        if ($namespace !== null) {
            return $namespace . '\\' . $className;
        }

        return $className;
    }
}
