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
use Pulsar\Support\AtomicFileWriter;
use Random\RandomException;
use RuntimeException;

use function array_any;
use function count;
use function dirname;
use function file_exists;
use function implode;
use function is_array;
use function ksort;
use function realpath;
use function sort;
use function sprintf;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Generates a deterministic OPcache preload script.
 *
 * The generated file is an immutable build artifact: not a runtime cache.
 * It should be placed in an immutable deploy path, never in var/cache/.
 *
 * Usage:
 *   preload:dump --output=preload.generated.php [--strict] [--no-meta]
 *
 * Flags:
 *   --strict   Fail if any classmap entry cannot be resolved (CI-friendly default)
 *   --lenient  Skip invalid entries with warnings instead of failing
 *   --no-meta  Suppress .meta.json sidecar generation
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class PreloadDumpCommand extends Command
{
    /** Hot-path namespaces to include in preload. */
    private const array HOT_PATH_NAMESPACES = [
        'Pulsar\\Core\\',
        'Pulsar\\Container\\',
        'Pulsar\\Http\\',
        'Pulsar\\Routing\\',
        'Pulsar\\Config\\',
        'Pulsar\\Security\\',
        'Pulsar\\Auth\\',
        'Pulsar\\Observability\\',
        'Pulsar\\ErrorHandling\\',
        'Pulsar\\Extensibility\\',
        'Pulsar\\Cache\\',
    ];

    private string $basePath;

    public function __construct(
        ?string $basePath = null,
    ) {
        $this->basePath = $basePath ?? dirname(__DIR__, 3);
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'preload:dump';
        $this->description = 'Generate a deterministic OPcache preload script';
        $this->addOption('output', 'Output file path (default: preload.generated.php)', 'o', 'preload.generated.php');
        $this->addOption('strict', 'Fail on invalid classmap entries (default)', 's');
        $this->addOption('lenient', 'Skip invalid entries with warnings instead of failing', 'l');
        $this->addOption('no-meta', 'Suppress .meta.json sidecar file generation');
    }

    /**
     * @throws RuntimeException If atomic file write fails
     * @throws RandomException If random byte generation fails
     * @throws JsonException If metadata JSON encoding fails
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputPath = $input->getStringOption('output', 'preload.generated.php');
        $strict = !$input->hasOption('lenient');
        $noMeta = $input->hasOption('no-meta');

        $output->writeln('Generating preload script...');

        // Load Composer classmap
        $classmapPath = $this->basePath . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_classmap.php';

        if (!file_exists($classmapPath)) {
            $output->writeln('  Error: Composer classmap not found at ' . $classmapPath);
            $output->writeln('  Run "composer dump-autoload --optimize" first.');

            return ExitCode::Error->value;
        }

        $classmap = require $classmapPath;

        if (!is_array($classmap)) {
            $output->writeln('  Error: Classmap is not a valid array.');

            return ExitCode::Error->value;
        }

        /** @var array<class-string, string> $classmap */

        // Resolve and validate paths
        $srcRoot = realpath($this->basePath . DIRECTORY_SEPARATOR . 'src');

        if ($srcRoot === false) {
            $output->writeln('  Error: Cannot resolve src/ directory.');

            return ExitCode::Error->value;
        }

        $resolvedPaths = [];
        $warnings = [];
        $skipped = 0;

        foreach ($classmap as $className => $classFile) {
            // Filter to hot-path namespaces
            if (!$this->isHotPath($className)) {
                continue;
            }

            $realPath = realpath($classFile);

            if ($realPath === false) {
                if ($strict) {
                    $output->writeln(sprintf('  Error: Cannot resolve path for %s: %s', $className, $classFile));

                    return ExitCode::Error->value;
                }

                $warnings[] = sprintf('  Skipped %s: file not found (%s)', $className, $classFile);
                $skipped++;

                continue;
            }

            if (!str_starts_with($realPath, $srcRoot . DIRECTORY_SEPARATOR)) {
                if ($strict) {
                    $output->writeln(sprintf('  Error: %s resolves outside src/: %s', $className, $realPath));

                    return ExitCode::Error->value;
                }

                $warnings[] = sprintf('  Skipped %s: outside src/ (%s)', $className, $realPath);
                $skipped++;

                continue;
            }

            $resolvedPaths[] = $realPath;
        }

        // Deduplicate and sort for deterministic output
        $resolvedPaths = array_values(array_unique($resolvedPaths));
        sort($resolvedPaths);

        // Print warnings if in lenient mode
        foreach ($warnings as $warning) {
            $output->writeln($warning);
        }

        // Generate preload PHP content
        $phpContent = $this->generatePhpContent($resolvedPaths);

        // Write PHP file atomically
        AtomicFileWriter::write($outputPath, $phpContent);
        $output->writeln(sprintf('  Preload script: %s (%d classes)', $outputPath, count($resolvedPaths)));

        // Write metadata sidecar
        if (!$noMeta) {
            $metaPath = $outputPath . '.meta.json';
            $metaContent = $this->generateMetaContent(count($resolvedPaths));
            AtomicFileWriter::write($metaPath, $metaContent);
            $output->writeln(sprintf('  Metadata: %s', $metaPath));
        }

        if ($skipped > 0) {
            $output->writeln(sprintf('  Warnings: %d entries skipped (lenient mode)', $skipped));
        }

        $output->writeln();
        $output->writeln('Preload script generated successfully.');
        $output->writeln('Configure in php.ini:');
        $output->writeln(sprintf('  opcache.preload = %s', realpath($outputPath) ?: $outputPath));
        $output->writeln('  opcache.preload_user = www-data');

        return ExitCode::Success->value;
    }

    /**
     * Check whether a class name belongs to a hot-path namespace.
     *
     * @param class-string $className
     */
    private function isHotPath(string $className): bool
    {
        return array_any(self::HOT_PATH_NAMESPACES, static fn(string $prefix): bool => str_starts_with($className, $prefix));
    }

    /**
     * Generate the deterministic PHP preload file content.
     *
     * Contains zero volatile data: no timestamps, PHP version, or hostname.
     *
     * @param list<string> $absolutePaths Sorted absolute file paths
     */
    public function generatePhpContent(array $absolutePaths): string
    {
        $lines = [
            '<?php',
            '',
            '/**',
            ' * Pulsar Framework: OPcache preload script.',
            ' *',
            ' * Generated by: php bin/pulsar preload:dump',
            ' *',
            ' * Configure in php.ini:',
            ' *   opcache.preload = /absolute/path/to/this/file',
            ' *   opcache.preload_user = www-data',
            ' *',
            ' * WARNING: This file is executed at server startup. Store it in an',
            ' * immutable deploy path. Never place executable preload scripts in',
            ' * writable directories (e.g., var/cache/).',
            ' */',
            '',
            'declare(strict_types=1);',
            '',
            "if (!function_exists('opcache_compile_file')) {",
            '    return;',
            '}',
            '',
        ];

        // Sort for deterministic output regardless of caller order
        $sortedPaths = $absolutePaths;
        sort($sortedPaths);

        foreach ($sortedPaths as $path) {
            // Normalize to forward slashes for consistency in generated output
            $normalizedPath = str_replace('\\', '/', $path);
            $lines[] = sprintf("opcache_compile_file('%s');", $normalizedPath);
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate the metadata sidecar JSON content.
     *
     * This file is informational only: never loaded by PHP, never
     * referenced by the preload script. Exists for human/CI inspection.
     *
     * @throws JsonException If JSON encoding fails
     */
    private function generateMetaContent(int $classCount): string
    {
        $meta = [
            'class_count' => $classCount,
            'generated_at' => date('c'),
            'generator' => 'pulsar preload:dump',
            'php_version' => PHP_VERSION,
        ];

        ksort($meta);

        return json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
