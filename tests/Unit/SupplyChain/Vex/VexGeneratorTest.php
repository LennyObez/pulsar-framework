<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Vex;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Vex\VexDocument;
use Pulsar\SupplyChain\Vex\VexGenerator;
use Pulsar\SupplyChain\Vex\VexJustification;
use Pulsar\SupplyChain\Vex\VexStatus;

use function json_encode;

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

        // Simulate that we import from the "vendor" namespace, specifically "Dangerous"
        $sourceImports = [
            'vendor' => ['Vendor\\Dangerous\\SomeClass', 'Vendor\\Other\\Helper'],
        ];

        $doc = $this->generator->generate($auditJson, $sourceImports);

        self::assertCount(1, $doc->statements);
        $stmt = $doc->statements[0];
        self::assertSame('CVE-2024-55555', $stmt->vulnerability);
        self::assertSame(VexStatus::Affected, $stmt->status);
        self::assertNull($stmt->justification);
        self::assertSame('vendor/dangerous', $stmt->product);
        self::assertStringContainsString('reachable', $stmt->actionStatement);
    }

    #[Test]
    public function generateDetectsNotAffectedWhenCodeIsNotReachable(): void
    {
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

        // Source imports nothing from the "vendor" namespace
        $sourceImports = [
            'other' => ['Other\\SomeClass'],
        ];

        $doc = $this->generator->generate($auditJson, $sourceImports);

        self::assertCount(1, $doc->statements);
        $stmt = $doc->statements[0];
        self::assertSame('CVE-2024-77777', $stmt->vulnerability);
        self::assertSame(VexStatus::NotAffected, $stmt->status);
        self::assertSame(VexJustification::VulnerableCodeNotInExecutePath, $stmt->justification);
        self::assertSame('vendor/vulnerable', $stmt->product);
        self::assertStringContainsString('not reachable', $stmt->actionStatement);
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
    public function notAffectedWhenVendorMatchesButPackageNamespaceDoesNot(): void
    {
        $auditJson = json_encode([
            'advisories' => [
                'vendor/specific-lib' => [
                    ['cve' => 'CVE-2024-88888', 'title' => 'Vuln in specific-lib'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // We import from vendor, but a different package (not "Specific-lib")
        $sourceImports = [
            'vendor' => ['Vendor\\OtherPackage\\Something'],
        ];

        $doc = $this->generator->generate($auditJson, $sourceImports);

        self::assertCount(1, $doc->statements);
        self::assertSame(VexStatus::NotAffected, $doc->statements[0]->status);
    }
}
