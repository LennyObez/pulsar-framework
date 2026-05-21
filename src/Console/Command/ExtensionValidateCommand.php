<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function file_exists;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Validate an extension's manifest and structure.
 *
 * Checks that the extension has a valid pulsar.json, required fields,
 * and that referenced classes exist.
 *
 * Usage: extension:validate <path>
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class ExtensionValidateCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->name = 'extension:validate';
        $this->description = 'Validate an extension manifest and directory structure';
        $this->addArgument('path', 'Path to the extension directory', true);
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument(0);

        if (!is_string($path) || $path === '') {
            $output->errorln('Extension path is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_dir($path)) {
            $output->errorln(sprintf('Directory does not exist: %s', $path));
            return ExitCode::Error->value;
        }

        $output->writeln(sprintf('Validating extension at: %s', $path));
        $output->newLine();

        $errors = 0;
        $warnings = 0;

        // Check pulsar.json
        $manifestPath = $path . DIRECTORY_SEPARATOR . 'pulsar.json';

        if (!file_exists($manifestPath)) {
            $output->errorln('  FAIL: pulsar.json not found');
            return ExitCode::Error->value;
        }

        $output->writeln('  PASS: pulsar.json exists');

        $content = file_get_contents($manifestPath);

        if ($content === false) {
            $output->errorln('  FAIL: Cannot read pulsar.json');
            return ExitCode::Error->value;
        }

        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $output->errorln(sprintf('  FAIL: Invalid JSON in pulsar.json: %s', $e->getMessage()));
            return ExitCode::Error->value;
        }

        $output->writeln('  PASS: Valid JSON');

        // Check required fields
        $requiredFields = ['name', 'version', 'extension_class'];

        foreach ($requiredFields as $field) {
            if (!isset($manifest[$field]) || !is_string($manifest[$field]) || $manifest[$field] === '') {
                $output->errorln(sprintf('  FAIL: Required field "%s" is missing or empty', $field));
                ++$errors;
            } else {
                $output->writeln(sprintf('  PASS: Field "%s" = "%s"', $field, $manifest[$field]));
            }
        }

        // Check src directory
        $srcDir = $path . DIRECTORY_SEPARATOR . 'src';

        if (!is_dir($srcDir)) {
            $output->writeln('  WARN: No src/ directory found');
            ++$warnings;
        } else {
            $output->writeln('  PASS: src/ directory exists');
        }

        // Check extension class exists
        if (isset($manifest['extension_class']) && is_string($manifest['extension_class'])) {
            $class = $manifest['extension_class'];

            if (class_exists($class)) {
                $output->writeln(sprintf('  PASS: Extension class "%s" is autoloadable', $class));
            } else {
                $output->writeln(sprintf('  WARN: Extension class "%s" is not autoloadable (may need composer dump-autoload)', $class));
                ++$warnings;
            }
        }

        // Check pulsar version constraint
        /** @var mixed $pulsarConfig */
        $pulsarConfig = $manifest['pulsar'] ?? null;

        if (is_array($pulsarConfig)) {
            /** @var mixed $minVersion */
            $minVersion = $pulsarConfig['min_version'] ?? null;

            if (is_string($minVersion)) {
                $output->writeln(sprintf('  INFO: Requires Pulsar >= %s', $minVersion));
            }
        }

        // Summary
        $output->newLine();

        if ($errors > 0) {
            $output->errorln(sprintf('Validation failed: %d error(s), %d warning(s)', $errors, $warnings));
            return ExitCode::Error->value;
        }

        if ($warnings > 0) {
            $output->warning(sprintf('Validation passed with %d warning(s)', $warnings));
        } else {
            $output->success('Extension is valid.');
        }

        return ExitCode::Success->value;
    }
}
