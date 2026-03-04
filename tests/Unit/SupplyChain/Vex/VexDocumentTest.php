<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Vex;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Vex\VexDocument;
use Pulsar\SupplyChain\Vex\VexJustification;
use Pulsar\SupplyChain\Vex\VexStatement;
use Pulsar\SupplyChain\Vex\VexStatus;

#[CoversClass(VexDocument::class)]
final class VexDocumentTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $timestamp = new DateTimeImmutable('2026-03-27T10:00:00+00:00');
        $statements = [
            new VexStatement(
                vulnerability: 'CVE-2024-12345',
                status: VexStatus::NotAffected,
                justification: VexJustification::ComponentNotPresent,
                product: 'vendor/package',
            ),
        ];

        $doc = new VexDocument(
            documentId: 'urn:uuid:test-id',
            version: 1,
            timestamp: $timestamp,
            tooling: 'pulsar-vex-generator/1.0.0',
            statements: $statements,
        );

        self::assertSame('urn:uuid:test-id', $doc->documentId);
        self::assertSame(1, $doc->version);
        self::assertSame($timestamp, $doc->timestamp);
        self::assertSame('pulsar-vex-generator/1.0.0', $doc->tooling);
        self::assertCount(1, $doc->statements);
        self::assertSame('CVE-2024-12345', $doc->statements[0]->vulnerability);
    }

    #[Test]
    public function documentCanHaveEmptyStatements(): void
    {
        $doc = new VexDocument(
            documentId: 'urn:uuid:empty',
            version: 1,
            timestamp: new DateTimeImmutable(),
            tooling: 'test',
            statements: [],
        );

        self::assertSame([], $doc->statements);
    }

    #[Test]
    public function documentCanHaveMultipleStatements(): void
    {
        $statements = [
            new VexStatement('CVE-2024-0001', VexStatus::Affected, product: 'pkg/a'),
            new VexStatement('CVE-2024-0002', VexStatus::Fixed, product: 'pkg/b'),
            new VexStatement('CVE-2024-0003', VexStatus::UnderInvestigation, product: 'pkg/c'),
        ];

        $doc = new VexDocument(
            documentId: 'urn:uuid:multi',
            version: 2,
            timestamp: new DateTimeImmutable(),
            tooling: 'test',
            statements: $statements,
        );

        self::assertCount(3, $doc->statements);
        self::assertSame(VexStatus::Affected, $doc->statements[0]->status);
        self::assertSame(VexStatus::Fixed, $doc->statements[1]->status);
        self::assertSame(VexStatus::UnderInvestigation, $doc->statements[2]->status);
    }

    #[Test]
    public function versionCanBeGreaterThanOne(): void
    {
        $doc = new VexDocument(
            documentId: 'urn:uuid:v5',
            version: 5,
            timestamp: new DateTimeImmutable(),
            tooling: 'test',
            statements: [],
        );

        self::assertSame(5, $doc->version);
    }
}
