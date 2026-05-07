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

use function array_key_exists;
use function bin2hex;
use function chr;
use function count;
use function date;
use function is_array;
use function is_string;
use function json_decode;
use function ord;
use function random_bytes;
use function sprintf;
use function str_contains;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Generates VEX documents by analyzing composer audit output and source imports.
 *
 * Runs `composer audit --format=json` to discover known vulnerabilities,
 * then checks whether the vulnerable code path is reachable by scanning
 * the source directory for imports of the affected package's namespace.
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
     * @param array<string, list<string>> $sourceImports Map of package name to list of imported
     *                                                   namespaces found in source. When empty,
     *                                                   the generator scans the source directory.
     */
    #[NoDiscard]
    public function generate(?string $composerAuditJson = null, array $sourceImports = []): VexDocument
    {
        $auditData = $composerAuditJson !== null
            ? $this->parseAuditJson($composerAuditJson)
            : $this->runComposerAudit();

        $importMap = $sourceImports !== []
            ? $sourceImports
            : $this->scanSourceImports();

        $statements = $this->buildStatements($auditData, $importMap);

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

        // nosemgrep: php.lang.security.exec-use.exec-use: array form bypasses the shell entirely (CWE-78 safe)
        $process = proc_open(
            ['composer', 'audit', '--format=json', '--no-interaction'],
            $descriptors,
            $pipes,
            $this->projectRoot,
        );

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
     * Scan the source directory for use/import statements to build a package reachability map.
     *
     * @return array<string, list<string>> Map of package name to found namespace prefixes
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

            // Extract use statements
            if (preg_match_all('/^use\s+([A-Z][a-zA-Z0-9\\\\]+)/m', $contents, $matches) > 0) {
                foreach ($matches[1] as $fqcn) {
                    $parts = explode('\\', $fqcn);
                    // Build vendor/package style key from top-level namespace
                    $vendorKey = strtolower($parts[0]);

                    if (!array_key_exists($vendorKey, $imports)) {
                        $imports[$vendorKey] = [];
                    }

                    $imports[$vendorKey][] = $fqcn;
                }
            }
        }

        return $imports;
    }

    /**
     * Build VEX statements from audit data and source import analysis.
     *
     * @param array<string, mixed> $auditData
     * @param array<string, list<string>> $importMap
     * @return list<VexStatement>
     */
    private function buildStatements(array $auditData, array $importMap): array
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

            /** @var mixed $advisory */
            foreach ($packageAdvisories as $advisory) {
                if (!is_array($advisory)) {
                    continue;
                }

                /** @var array<string, mixed> $advisory */
                /** @var mixed $rawCve */
                $rawCve = $advisory['cve'] ?? null;
                $cve = is_string($rawCve) ? $rawCve : '';

                if ($cve === '') {
                    /** @var mixed $rawAdvisoryId */
                    $rawAdvisoryId = $advisory['advisoryId'] ?? null;
                    // Use advisory ID as fallback identifier
                    $cve = is_string($rawAdvisoryId) ? $rawAdvisoryId : 'UNKNOWN';
                }

                $statement = $this->assessVulnerability($packageName, $cve, $advisory, $importMap);
                $statements[] = $statement;
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
     * @param array<string, list<string>> $importMap
     */
    private function assessVulnerability(
        string $packageName,
        string $cve,
        array $advisory,
        array $importMap,
    ): VexStatement {
        // Check if the package namespace is imported anywhere in source
        $vendorParts = explode('/', $packageName);
        $vendorKey = strtolower($vendorParts[0]);

        $isReachable = array_key_exists($vendorKey, $importMap);

        // Additional check: look for the specific package namespace
        if ($isReachable && count($vendorParts) > 1) {
            $packageNamespace = ucfirst($vendorParts[1]);
            $found = false;

            foreach ($importMap[$vendorKey] as $import) {
                if (str_contains($import, $packageNamespace)) {
                    $found = true;

                    break;
                }
            }

            $isReachable = $found;
        }

        /** @var mixed $rawTitle */
        $rawTitle = $advisory['title'] ?? null;
        $title = is_string($rawTitle) ? $rawTitle : '';

        if ($isReachable) {
            return new VexStatement(
                vulnerability: $cve,
                status: VexStatus::Affected,
                actionStatement: sprintf('Vulnerable code in %s is reachable. %s', $packageName, $title),
                product: $packageName,
            );
        }

        return new VexStatement(
            vulnerability: $cve,
            status: VexStatus::NotAffected,
            justification: VexJustification::VulnerableCodeNotInExecutePath,
            actionStatement: sprintf(
                'Package %s is installed but the vulnerable code path is not reachable from source. %s',
                $packageName,
                $title,
            ),
            product: $packageName,
        );
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
