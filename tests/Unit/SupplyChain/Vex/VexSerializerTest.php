<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Vex;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Vex\VexDocument;
use Pulsar\SupplyChain\Vex\VexJustification;
use Pulsar\SupplyChain\Vex\VexSerializer;
use Pulsar\SupplyChain\Vex\VexStatement;
use Pulsar\SupplyChain\Vex\VexStatus;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(VexSerializer::class)]
final class VexSerializerTest extends TestCase
{
    private VexSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new VexSerializer();
    }

    #[Test]
    public function serializeProducesValidJson(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $json = $this->serializer->serialize($doc);

        // Must parse without error
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
    }

    #[Test]
    public function serializeIncludesOpenVexContext(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);

        self::assertSame('https://openvex.dev/ns/v0.2.0', $data['@context']);
    }

    #[Test]
    public function serializeIncludesDocumentId(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);

        self::assertSame('urn:uuid:test-123', $data['@id']);
    }

    #[Test]
    public function serializeIncludesToolingAndAuthor(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);

        self::assertSame('pulsar-vex-generator/1.0.0', $data['tooling']);
        self::assertSame('pulsar-vex-generator/1.0.0', $data['author']);
    }

    #[Test]
    public function serializeIncludesTimestamp(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);

        self::assertArrayHasKey('timestamp', $data);
        $timestamp = $data['timestamp'];
        self::assertIsString($timestamp);
        self::assertStringContainsString('2026-03-27', $timestamp);
    }

    #[Test]
    public function serializeIncludesVersion(): void
    {
        $doc = new VexDocument(
            documentId: 'urn:uuid:ver-test',
            version: 3,
            timestamp: new DateTimeImmutable('2026-03-27T10:00:00+00:00'),
            tooling: 'test',
            statements: [],
        );

        $data = $this->decodeSerializedDocument($doc);

        self::assertSame(3, $data['version']);
    }

    #[Test]
    public function serializeIncludesStatementsArray(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);

        $statements = $this->extractStatements($data);
        self::assertCount(1, $statements);
    }

    #[Test]
    public function statementIncludesVulnerabilityBlock(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);
        $stmt = $this->extractStatement($data, 0);

        self::assertArrayHasKey('vulnerability', $stmt);
        $vuln = $stmt['vulnerability'];
        self::assertIsArray($vuln);
        self::assertSame('CVE-2024-12345', $vuln['@id']);
        self::assertSame('CVE-2024-12345', $vuln['name']);
    }

    #[Test]
    public function statementIncludesProductBlock(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);
        $stmt = $this->extractStatement($data, 0);

        self::assertArrayHasKey('products', $stmt);
        $products = $stmt['products'];
        self::assertIsArray($products);
        $firstProduct = $products[0];
        self::assertIsArray($firstProduct);
        self::assertSame('vendor/package', $firstProduct['@id']);
    }

    #[Test]
    public function statementIncludesStatus(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);
        $stmt = $this->extractStatement($data, 0);

        self::assertSame('not_affected', $stmt['status']);
    }

    #[Test]
    public function statementIncludesJustificationWhenPresent(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);
        $stmt = $this->extractStatement($data, 0);

        self::assertSame('vulnerable_code_not_in_execute_path', $stmt['justification']);
    }

    #[Test]
    public function statementOmitsJustificationWhenNull(): void
    {
        $doc = new VexDocument(
            documentId: 'urn:uuid:no-just',
            version: 1,
            timestamp: new DateTimeImmutable(),
            tooling: 'test',
            statements: [
                new VexStatement('CVE-2024-00000', VexStatus::Affected, product: 'pkg/a'),
            ],
        );

        $data = $this->decodeSerializedDocument($doc);
        $stmt = $this->extractStatement($data, 0);

        self::assertArrayNotHasKey('justification', $stmt);
    }

    #[Test]
    public function statementIncludesActionStatementWhenPresent(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);
        $stmt = $this->extractStatement($data, 0);

        self::assertSame('Code path not reachable.', $stmt['action_statement']);
    }

    #[Test]
    public function statementOmitsActionStatementWhenEmpty(): void
    {
        $doc = new VexDocument(
            documentId: 'urn:uuid:no-action',
            version: 1,
            timestamp: new DateTimeImmutable(),
            tooling: 'test',
            statements: [
                new VexStatement('CVE-2024-11111', VexStatus::Fixed, product: 'pkg/a'),
            ],
        );

        $data = $this->decodeSerializedDocument($doc);
        $stmt = $this->extractStatement($data, 0);

        self::assertArrayNotHasKey('action_statement', $stmt);
    }

    #[Test]
    public function serializeHandlesEmptyStatements(): void
    {
        $doc = new VexDocument(
            documentId: 'urn:uuid:empty',
            version: 1,
            timestamp: new DateTimeImmutable(),
            tooling: 'test',
            statements: [],
        );

        $data = $this->decodeSerializedDocument($doc);

        self::assertSame([], $this->extractStatements($data));
    }

    #[Test]
    public function serializeHandlesMultipleStatements(): void
    {
        $doc = new VexDocument(
            documentId: 'urn:uuid:multi',
            version: 1,
            timestamp: new DateTimeImmutable(),
            tooling: 'test',
            statements: [
                new VexStatement('CVE-2024-00001', VexStatus::Affected, product: 'a/b'),
                new VexStatement('CVE-2024-00002', VexStatus::Fixed, product: 'c/d'),
            ],
        );

        $data = $this->decodeSerializedDocument($doc);
        $statements = $this->extractStatements($data);

        self::assertCount(2, $statements);
        self::assertSame('affected', $statements[0]['status']);
        self::assertSame('fixed', $statements[1]['status']);
    }

    #[Test]
    public function serializeIncludesRoleField(): void
    {
        $doc = $this->createDocumentWithOneStatement();

        $data = $this->decodeSerializedDocument($doc);

        self::assertSame('document_author', $data['role']);
    }

    private function createDocumentWithOneStatement(): VexDocument
    {
        return new VexDocument(
            documentId: 'urn:uuid:test-123',
            version: 1,
            timestamp: new DateTimeImmutable('2026-03-27T10:00:00+00:00'),
            tooling: 'pulsar-vex-generator/1.0.0',
            statements: [
                new VexStatement(
                    vulnerability: 'CVE-2024-12345',
                    status: VexStatus::NotAffected,
                    justification: VexJustification::VulnerableCodeNotInExecutePath,
                    actionStatement: 'Code path not reachable.',
                    product: 'vendor/package',
                ),
            ],
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function extractStatements(array $data): array
    {
        self::assertArrayHasKey('statements', $data);
        $statements = $data['statements'];
        self::assertIsArray($statements);

        $result = [];
        foreach ($statements as $stmt) {
            self::assertIsArray($stmt);
            /** @var array<string, mixed> $stmt */
            $result[] = $stmt;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractStatement(array $data, int $index): array
    {
        $statements = $this->extractStatements($data);
        self::assertArrayHasKey($index, $statements);

        return $statements[$index];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSerializedDocument(VexDocument $doc): array
    {
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
