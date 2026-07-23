<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Vex;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Vex\VexDocument;
use Pulsar\SupplyChain\Vex\VexGenerator;
use Pulsar\SupplyChain\Vex\VexStatus;

use function bin2hex;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

#[CoversClass(VexGenerator::class)]
final class VexGeneratorTest extends TestCase
{
    private VexGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new VexGenerator('/fake/project');
    }

    #[Test]
    public function generateReturnsVexDocumentWithNoVulnerabilities(): void
    {
        $auditJson = json_encode([], JSON_THROW_ON_ERROR);

        $doc = $this->generator->generate($auditJson, []);

        self::assertInstanceOf(VexDocument::class, $doc);
        self::assertSame([], $doc->statements);
        self::assertStringStartsWith('urn:uuid:', $doc->documentId);
        self::assertSame(1, $doc->version);
        self::assertStringContainsString('pulsar-vex-generator', $doc->tooling);
    }

    #[Test]
    public function generateDetectsAffectedPackageWhenCodeIsReachable(): void
    {
        $auditJson = json_encode([
            'advisories' => [
                'vendor/dangerous' => [
                    [
                        'cve' => 'CVE-2024-55555',
                        'title' => 'Remote code execution in Dangerous class',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // The package's real PSR-4 namespace (from composer.lock) is imported in source.
        $sourceImports = ['Vendor\\Dangerous\\SomeClass', 'Vendor\\Other\\Helper'];
        $packageNamespaces = ['vendor/dangerous' => ['Vendor\\Dangerous\\']];

        $doc = $this->generator->generate($auditJson, $sourceImports, $packageNamespaces);

        self::assertCount(1, $doc->statements);
        $stmt = $doc->statements[0];
        self::assertSame('CVE-2024-55555', $stmt->vulnerability);
        self::assertSame(VexStatus::Affected, $stmt->status);
        self::assertNull($stmt->justification);
        self::assertSame('vendor/dangerous', $stmt->product);
        self::assertStringContainsString('reachable', $stmt->actionStatement);
    }

    #[Test]
    public function underInvestigationWhenTheNamespaceIsNotImported(): void
    {
        // C21 regression: absence of an import must NOT be reported as not_affected.
        // A source-only scan cannot prove non-reachability (transitive / dynamic use),
        // so the honest status is under_investigation, never a false clearance.
        $auditJson = json_encode([
            'advisories' => [
                'vendor/vulnerable' => [
                    [
                        'cve' => 'CVE-2024-77777',
                        'title' => 'SQL injection in ORM layer',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $sourceImports = ['Other\\SomeClass'];
        $packageNamespaces = ['vendor/vulnerable' => ['Vendor\\Vulnerable\\']];

        $doc = $this->generator->generate($auditJson, $sourceImports, $packageNamespaces);

        self::assertCount(1, $doc->statements);
        $stmt = $doc->statements[0];
        self::assertSame('CVE-2024-77777', $stmt->vulnerability);
        self::assertSame(VexStatus::UnderInvestigation, $stmt->status);
        self::assertNull($stmt->justification);
        self::assertSame('vendor/vulnerable', $stmt->product);
        self::assertStringContainsString('reviewed', $stmt->actionStatement);
    }

    #[Test]
    public function underInvestigationWhenPackageHasNoMappableNamespace(): void
    {
        // Transitive dependencies never appear in a first-party import scan and have
        // no namespace entry to map, so they must default to under_investigation —
        // the old heuristic silently cleared every one of them as not_affected.
        $auditJson = json_encode([
            'advisories' => [
                'transitive/dep' => [
                    ['cve' => 'CVE-2024-33333', 'title' => 'Deserialization flaw'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Package is not present in the namespace map (unresolved / transitive).
        $doc = $this->generator->generate($auditJson, ['App\\Kernel'], []);

        self::assertCount(1, $doc->statements);
        $stmt = $doc->statements[0];
        self::assertSame(VexStatus::UnderInvestigation, $stmt->status);
        self::assertStringContainsString('undetermined', $stmt->actionStatement);
    }

    #[Test]
    public function generateHandlesMultipleAdvisoriesPerPackage(): void
    {
        $auditJson = json_encode([
            'advisories' => [
                'vendor/lib' => [
                    ['cve' => 'CVE-2024-00001', 'title' => 'First vuln'],
                    ['cve' => 'CVE-2024-00002', 'title' => 'Second vuln'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $doc = $this->generator->generate($auditJson, []);

        self::assertCount(2, $doc->statements);
        self::assertSame('CVE-2024-00001', $doc->statements[0]->vulnerability);
        self::assertSame('CVE-2024-00002', $doc->statements[1]->vulnerability);
    }

    #[Test]
    public function generateHandlesMultiplePackages(): void
    {
        $auditJson = json_encode([
            'advisories' => [
                'alpha/one' => [
                    ['cve' => 'CVE-2024-10001', 'title' => 'Alpha vuln'],
                ],
                'beta/two' => [
                    ['cve' => 'CVE-2024-10002', 'title' => 'Beta vuln'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $doc = $this->generator->generate($auditJson, []);

        self::assertCount(2, $doc->statements);

        $cves = [$doc->statements[0]->vulnerability, $doc->statements[1]->vulnerability];
        self::assertContains('CVE-2024-10001', $cves);
        self::assertContains('CVE-2024-10002', $cves);
    }

    #[Test]
    public function generateUsesAdvisoryIdAsFallbackWhenCveMissing(): void
    {
        $auditJson = json_encode([
            'advisories' => [
                'vendor/pkg' => [
                    ['advisoryId' => 'GHSA-xxxx-yyyy', 'title' => 'Some issue'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $doc = $this->generator->generate($auditJson, []);

        self::assertCount(1, $doc->statements);
        self::assertSame('GHSA-xxxx-yyyy', $doc->statements[0]->vulnerability);
    }

    #[Test]
    public function generateUsesUnknownWhenNoCveOrAdvisoryId(): void
    {
        $auditJson = json_encode([
            'advisories' => [
                'vendor/pkg' => [
                    ['title' => 'Unnamed vulnerability'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $doc = $this->generator->generate($auditJson, []);

        self::assertCount(1, $doc->statements);
        self::assertSame('UNKNOWN', $doc->statements[0]->vulnerability);
    }

    #[Test]
    public function generateHandlesEmptyAuditJson(): void
    {
        $doc = $this->generator->generate('{}', []);

        self::assertSame([], $doc->statements);
    }

    #[Test]
    public function generateHandlesMalformedAuditJson(): void
    {
        $doc = $this->generator->generate('not json at all', []);

        self::assertSame([], $doc->statements);
    }

    #[Test]
    public function generateHandlesEmptyStringAuditJson(): void
    {
        $doc = $this->generator->generate('', []);

        self::assertSame([], $doc->statements);
    }

    #[Test]
    public function generateHandlesEmptyArrayAuditJson(): void
    {
        $doc = $this->generator->generate('[]', []);

        self::assertSame([], $doc->statements);
    }

    #[Test]
    public function documentIdsAreUniqueAcrossGenerations(): void
    {
        $auditJson = json_encode([], JSON_THROW_ON_ERROR);

        $doc1 = $this->generator->generate($auditJson, []);
        $doc2 = $this->generator->generate($auditJson, []);

        self::assertNotSame($doc1->documentId, $doc2->documentId);
    }

    #[Test]
    public function generateSkipsNonArrayAdvisoryEntries(): void
    {
        $auditJson = json_encode([
            'advisories' => [
                'vendor/pkg' => [
                    'not-an-array',
                    ['cve' => 'CVE-2024-99999', 'title' => 'Real'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $doc = $this->generator->generate($auditJson, []);

        self::assertCount(1, $doc->statements);
        self::assertSame('CVE-2024-99999', $doc->statements[0]->vulnerability);
    }

    #[Test]
    public function neverEmitsNotAffectedFromTheReachabilityHeuristic(): void
    {
        // The whole point of the fix: this generator must never assert not_affected
        // from a source scan, regardless of imports, because that claim is unsound.
        $auditJson = json_encode([
            'advisories' => [
                'vendor/specific-lib' => [
                    ['cve' => 'CVE-2024-88888', 'title' => 'Vuln in specific-lib'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // We import a different package under the same vendor.
        $sourceImports = ['Vendor\\OtherPackage\\Something'];
        $packageNamespaces = ['vendor/specific-lib' => ['Vendor\\SpecificLib\\']];

        $doc = $this->generator->generate($auditJson, $sourceImports, $packageNamespaces);

        self::assertCount(1, $doc->statements);
        self::assertNotSame(VexStatus::NotAffected, $doc->statements[0]->status);
        self::assertSame(VexStatus::UnderInvestigation, $doc->statements[0]->status);
    }

    #[Test]
    public function mapsPackagesToNamespacesFromComposerLockNotTheVendorSegment(): void
    {
        // End-to-end proof of the fix: the composer *vendor* is not a namespace.
        // `nikic/php-parser` registers `PhpParser\`; the old heuristic keyed on the
        // vendor segment `nikic` and so never matched a `use PhpParser\...`, falsely
        // clearing it. With composer.lock parsing the import is correctly reachable.
        $dir = sys_get_temp_dir() . '/vexgen_' . bin2hex(random_bytes(6));
        mkdir($dir . '/src', 0o777, true);

        file_put_contents($dir . '/composer.lock', (string) json_encode([
            'packages' => [
                ['name' => 'nikic/php-parser', 'autoload' => ['psr-4' => ['PhpParser\\' => 'lib/']]],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/src/Uses.php', "<?php\n\ndeclare(strict_types=1);\n\nuse PhpParser\\Parser;\n");

        $audit = (string) json_encode([
            'advisories' => [
                'nikic/php-parser' => [['cve' => 'CVE-2024-12345', 'title' => 'Parser flaw']],
            ],
        ], JSON_THROW_ON_ERROR);

        $doc = new VexGenerator($dir)->generate($audit);

        unlink($dir . '/src/Uses.php');
        unlink($dir . '/composer.lock');
        rmdir($dir . '/src');
        rmdir($dir);

        self::assertCount(1, $doc->statements);
        self::assertSame(VexStatus::Affected, $doc->statements[0]->status);
        self::assertStringContainsString('reachable', $doc->statements[0]->actionStatement);
    }
}
