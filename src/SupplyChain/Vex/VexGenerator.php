<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Vex;

use DateTimeImmutable;
use FilesystemIterator;
use JsonException;
use NoDiscard;
use Pulsar\Api\Internal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function bin2hex;
use function chr;
use function count;
use function date;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function ord;
use function random_bytes;
use function restore_error_handler;
use function rtrim;
use function set_error_handler;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Generates VEX documents by analyzing composer audit output and source imports.
 *
 * Runs `composer audit --format=json` to discover known vulnerabilities, then
 * decides exploitability by mapping each vulnerable composer package to the
 * PSR-4/PSR-0 namespace prefixes it actually registers (read from
 * composer.lock) and checking whether any of those namespaces is imported in
 * the scanned source.
 *
 * A source-only import scan can PROVE reachability (an explicit `use` of the
 * package's namespace) but can never prove NON-reachability: transitive
 * dependencies leave no first-party import, and dynamic dispatch leaves none
 * either. Asserting `not_affected` from the absence of an import is therefore
 * unsound and is exactly what produced false "vulnerable_code_not_in_execute_path"
 * clearances. This generator never emits `not_affected` from the heuristic; when
 * reachability cannot be positively established it emits `under_investigation`.
 *
 * Uses proc_open with array arguments to prevent shell injection (CWE-78).
 */
#[Internal(reason: 'VEX generation implementation detail')]
final readonly class VexGenerator
{
    private const string TOOLING = 'pulsar-vex-generator/1.0.0';

    /**
     * @param string $projectRoot Absolute path to project root
     * @param string $sourceDir Relative path to source directory (e.g. "src")
     */
    public function __construct(
        private string $projectRoot,
        private string $sourceDir = 'src',
    ) {}

    /**
     * Generate a VEX document from composer audit results.
     *
     * @param string|null $composerAuditJson Pre-fetched JSON output from `composer audit --format=json`.
     *                                       When null, the generator runs the command itself.
     * @param list<string> $sourceImports Fully-qualified class names imported in the scanned source.
     *                                    When empty, the generator scans the source directory.
     * @param array<string, list<string>> $packageNamespaces Map of composer package name to the
     *                                    PSR-4/PSR-0 namespace prefixes it registers. When empty,
     *                                    the generator resolves it from composer.lock.
     */
    #[NoDiscard]
    public function generate(
        ?string $composerAuditJson = null,
        array $sourceImports = [],
        array $packageNamespaces = [],
    ): VexDocument {
        $auditData = $composerAuditJson !== null
            ? $this->parseAuditJson($composerAuditJson)
            : $this->runComposerAudit();

        $imports = $sourceImports !== []
            ? $sourceImports
            : $this->scanSourceImports();

        $namespaceMap = $packageNamespaces !== []
            ? $packageNamespaces
            : $this->resolvePackageNamespaces();

        $statements = $this->buildStatements($auditData, $imports, $namespaceMap);

        return new VexDocument(
            documentId: sprintf('urn:uuid:%s', self::generateUuidV4()),
            version: 1,
            timestamp: new DateTimeImmutable(date('c')),
            tooling: self::TOOLING,
            statements: $statements,
        );
    }

    /**
     * Run `composer audit --format=json` and parse the output.
     *
     * @return array<string, mixed>
     */
    private function runComposerAudit(): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // The command is a constant literal array and the array form bypasses the
        // shell entirely, so no user input ever reaches a command line (CWE-78 and
        // CWE-94 safe). proc_open() emits an E_WARNING when the binary is absent
        // (e.g. `composer` resolves to composer.bat on Windows and is not found via
        // the array form); that is a handled degradation (false return below), so
        // the warning is suppressed with a scoped error handler.
        set_error_handler(static fn(): bool => true);

        try {
            // nosemgrep: php.lang.security.exec-use.exec-use
            $process = proc_open(
                ['composer', 'audit', '--format=json', '--no-interaction'],
                $descriptors,
                $pipes,
                $this->projectRoot,
            );
        } finally {
            restore_error_handler();
        }

        if ($process === false) {
            return [];
        }

        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        if (!is_string($stdout) || trim($stdout) === '') {
            return [];
        }

        return $this->parseAuditJson($stdout);
    }

    /**
     * Parse composer audit JSON output into structured data.
     *
     * @return array<string, mixed>
     */
    private function parseAuditJson(string $json): array
    {
        $trimmed = trim($json);

        if ($trimmed === '' || $trimmed === '[]') {
            return [];
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);

            return $data;
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * Scan the source directory for use-imports, returning every fully-qualified
     * class name imported anywhere in the source.
     *
     * @return list<string>
     */
    private function scanSourceImports(): array
    {
        $srcPath = $this->projectRoot . '/' . $this->sourceDir;

        if (!is_dir($srcPath)) {
            return [];
        }

        $imports = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcPath, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (!is_string($contents)) {
                continue;
            }

            if (preg_match_all('/^use\s+(?:function\s+|const\s+)?([A-Z][a-zA-Z0-9\\\\]+)/m', $contents, $matches) > 0) {
                foreach ($matches[1] as $fqcn) {
                    $imports[$fqcn] = true;
                }
            }
        }

        return array_keys($imports);
    }

    /**
     * Map every installed composer package (direct AND transitive) to the PSR-4 /
     * PSR-0 namespace prefixes it registers, read from composer.lock. This is the
     * authoritative package -> namespace mapping; the composer *vendor* segment is
     * NOT a namespace (e.g. `nikic/php-parser` registers `PhpParser\`, not `nikic`)
     * and must never be used as one.
     *
     * @return array<string, list<string>>
     */
    private function resolvePackageNamespaces(): array
    {
        $lockPath = $this->projectRoot . '/composer.lock';
        $raw = is_file($lockPath) ? file_get_contents($lockPath) : false;

        if (!is_string($raw)) {
            return [];
        }

        try {
            /** @var mixed $lock */
            $lock = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($lock)) {
            return [];
        }

        $map = [];

        foreach (['packages', 'packages-dev'] as $section) {
            /** @var mixed $packages */
            $packages = $lock[$section] ?? null;

            if (!is_array($packages)) {
                continue;
            }

            /** @var mixed $package */
            foreach ($packages as $package) {
                if (!is_array($package)) {
                    continue;
                }

                /** @var mixed $name */
                $name = $package['name'] ?? null;

                if (!is_string($name) || $name === '') {
                    continue;
                }

                /** @var array<string, mixed> $package */
                $prefixes = $this->extractNamespacePrefixes($package);

                if ($prefixes !== []) {
                    $map[$name] = $prefixes;
                }
            }
        }

        return $map;
    }

    /**
     * Collect the PSR-4/PSR-0 namespace prefixes a package registers. The empty
     * (root) prefix is skipped: it maps to every class and cannot discriminate
     * reachability.
     *
     * @param array<string, mixed> $package
     * @return list<string>
     */
    private function extractNamespacePrefixes(array $package): array
    {
        /** @var mixed $autoload */
        $autoload = $package['autoload'] ?? null;

        if (!is_array($autoload)) {
            return [];
        }

        $prefixes = [];

        foreach (['psr-4', 'psr-0'] as $scheme) {
            /** @var mixed $scheduled */
            $scheduled = $autoload[$scheme] ?? null;

            if (!is_array($scheduled)) {
                continue;
            }

            foreach ($scheduled as $prefix => $ignored) {
                if (!is_string($prefix) || $prefix === '') {
                    continue;
                }

                $prefixes[$prefix] = true;
            }
        }

        return array_keys($prefixes);
    }

    /**
     * Build VEX statements from audit data and source import analysis.
     *
     * @param array<string, mixed> $auditData
     * @param list<string> $sourceImports
     * @param array<string, list<string>> $packageNamespaces
     * @return list<VexStatement>
     */
    private function buildStatements(array $auditData, array $sourceImports, array $packageNamespaces): array
    {
        $statements = [];

        /** @var mixed $rawAdvisories */
        $rawAdvisories = $auditData['advisories'] ?? null;
        /** @var array<string, mixed> $advisories */
        $advisories = is_array($rawAdvisories) ? $rawAdvisories : [];

        foreach ($advisories as $packageName => $packageAdvisories) {
            if (!is_array($packageAdvisories)) {
                continue;
            }

            /** @var mixed $advisoryRaw */
            foreach ($packageAdvisories as $advisoryRaw) {
                if (!is_array($advisoryRaw)) {
                    continue;
                }

                /** @var array<string, mixed> $advisory */
                $advisory = $advisoryRaw;
                /** @var mixed $rawCve */
                $rawCve = $advisory['cve'] ?? null;
                $cve = is_string($rawCve) ? $rawCve : '';

                if ($cve === '') {
                    /** @var mixed $rawAdvisoryId */
                    $rawAdvisoryId = $advisory['advisoryId'] ?? null;
                    // Use advisory ID as fallback identifier
                    $cve = is_string($rawAdvisoryId) ? $rawAdvisoryId : 'UNKNOWN';
                }

                $statements[] = $this->assessVulnerability(
                    (string) $packageName,
                    $cve,
                    $advisory,
                    $sourceImports,
                    $packageNamespaces,
                );
            }
        }

        // If no advisories found, audit is clean
        if ($statements === [] && count($auditData) === 0) {
            return [];
        }

        return $statements;
    }

    /**
     * Assess a single vulnerability and produce a VEX statement.
     *
     * @param array<string, mixed> $advisory
     * @param list<string> $sourceImports
     * @param array<string, list<string>> $packageNamespaces
     */
    private function assessVulnerability(
        string $packageName,
        string $cve,
        array $advisory,
        array $sourceImports,
        array $packageNamespaces,
    ): VexStatement {
        /** @var mixed $rawTitle */
        $rawTitle = $advisory['title'] ?? null;
        $title = is_string($rawTitle) ? $rawTitle : '';

        $prefixes = $packageNamespaces[$packageName] ?? [];

        if ($this->isReachable($sourceImports, $prefixes)) {
            return new VexStatement(
                vulnerability: $cve,
                status: VexStatus::Affected,
                actionStatement: trim(sprintf(
                    'Vulnerable package %s is imported in source, so its code is reachable. %s',
                    $packageName,
                    $title,
                )),
                product: $packageName,
            );
        }

        // Absence of an import does NOT prove non-reachability (transitive
        // dependencies and dynamic dispatch leave no `use`), so we never assert
        // not_affected here — we flag it for investigation instead.
        $reason = $prefixes === []
            ? sprintf(
                'Package %s could not be mapped to an autoloaded namespace (transitive dependency, or classmap/files autoload), so reachability is undetermined and must be reviewed. %s',
                $packageName,
                $title,
            )
            : sprintf(
                'Package %s is installed but no source import of its namespace was found; a source-only scan cannot rule out transitive or dynamic use, so reachability must be reviewed. %s',
                $packageName,
                $title,
            );

        return new VexStatement(
            vulnerability: $cve,
            status: VexStatus::UnderInvestigation,
            actionStatement: trim($reason),
            product: $packageName,
        );
    }

    /**
     * A package is reachable when the source imports any class under one of the
     * namespace prefixes it registers. Matching is case-insensitive (PHP
     * namespaces are) and anchored on a namespace boundary so `Vendor\Pkg\` does
     * not match `Vendor\PkgExtra\`.
     *
     * @param list<string> $sourceImports
     * @param list<string> $prefixes
     */
    private function isReachable(array $sourceImports, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            $needle = strtolower(rtrim($prefix, '\\')) . '\\';

            foreach ($sourceImports as $import) {
                if (str_starts_with(strtolower($import) . '\\', $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6)),
        );
    }
}
