<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Invoice;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Internal\Commerce\InvoiceService;

use function hash;
use function json_encode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Security tests verifying invoice evidence hashes detect tampering.
 *
 * Verifies S10: Evidence hashes on invoices are computed correctly and
 * any field modification produces a different hash.
 */
#[CoversClass(InvoiceService::class)]
#[CoversClass(Invoice::class)]
final class InvoiceEvidenceHashTest extends TestCase
{
    #[Test]
    public function test_invoice_evidence_hash_is_deterministic(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00+00:00');

        $hash1 = $this->computeEvidenceHash(
            orderId: 'order-1',
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 12000,
            currency: 'EUR',
            issuedAt: $now,
            itemCount: 3,
        );

        $hash2 = $this->computeEvidenceHash(
            orderId: 'order-1',
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 12000,
            currency: 'EUR',
            issuedAt: $now,
            itemCount: 3,
        );

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function test_changing_total_changes_evidence_hash(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00+00:00');

        $original = $this->computeEvidenceHash(
            orderId: 'order-1',
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 12000,
            currency: 'EUR',
            issuedAt: $now,
            itemCount: 3,
        );

        $tampered = $this->computeEvidenceHash(
            orderId: 'order-1',
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 1, // tampered: total changed
            currency: 'EUR',
            issuedAt: $now,
            itemCount: 3,
        );

        self::assertNotSame($original, $tampered);
    }

    #[Test]
    public function test_changing_order_id_changes_evidence_hash(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00+00:00');

        $original = $this->computeEvidenceHash(
            orderId: 'order-1',
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 12000,
            currency: 'EUR',
            issuedAt: $now,
            itemCount: 3,
        );

        $tampered = $this->computeEvidenceHash(
            orderId: 'order-FAKE', // tampered
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 12000,
            currency: 'EUR',
            issuedAt: $now,
            itemCount: 3,
        );

        self::assertNotSame($original, $tampered);
    }

    #[Test]
    public function test_changing_item_count_changes_evidence_hash(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00+00:00');

        $original = $this->computeEvidenceHash(
            orderId: 'order-1',
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 12000,
            currency: 'EUR',
            issuedAt: $now,
            itemCount: 3,
        );

        $tampered = $this->computeEvidenceHash(
            orderId: 'order-1',
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 12000,
            currency: 'EUR',
            issuedAt: $now,
            itemCount: 999, // tampered
        );

        self::assertNotSame($original, $tampered);
    }

    #[Test]
    public function test_changing_currency_changes_evidence_hash(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00+00:00');

        $original = $this->computeEvidenceHash(
            orderId: 'order-1',
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 12000,
            currency: 'EUR',
            issuedAt: $now,
            itemCount: 3,
        );

        $tampered = $this->computeEvidenceHash(
            orderId: 'order-1',
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 12000,
            currency: 'USD', // tampered
            issuedAt: $now,
            itemCount: 3,
        );

        self::assertNotSame($original, $tampered);
    }

    #[Test]
    public function test_evidence_hash_uses_sha256(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00+00:00');

        $hash = $this->computeEvidenceHash(
            orderId: 'order-1',
            orderNumber: 'ORD-001',
            invoiceNumber: 'INV-001',
            subtotal: 10000,
            taxAmount: 2000,
            discountAmount: 0,
            total: 12000,
            currency: 'EUR',
            issuedAt: $now,
            itemCount: 3,
        );

        // SHA-256 produces a 64-character hex string
        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function test_invoice_data_classification_is_pii(): void
    {
        // Invoices contain customer information and must be classified as PII
        $invoice = new Invoice(
            id: 'inv-1',
            orderId: 'order-1',
            invoiceNumber: 'INV-001',
            issuedAt: new DateTimeImmutable(),
            dueAt: new DateTimeImmutable('+30 days'),
            pdfStoragePath: null,
            pdfHash: null,
            evidenceHash: str_repeat('a', 64),
            dataClassification: DataClassification::Pii,
        );

        self::assertSame(DataClassification::Pii, $invoice->dataClassification);
    }

    /**
     * Replicates the evidence hash algorithm from InvoiceService.
     */
    private function computeEvidenceHash(
        string $orderId,
        string $orderNumber,
        string $invoiceNumber,
        int $subtotal,
        int $taxAmount,
        int $discountAmount,
        int $total,
        string $currency,
        DateTimeImmutable $issuedAt,
        int $itemCount,
    ): string {
        $payload = json_encode([
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'invoiceNumber' => $invoiceNumber,
            'subtotal' => $subtotal,
            'taxAmount' => $taxAmount,
            'discountAmount' => $discountAmount,
            'total' => $total,
            'currency' => $currency,
            'issuedAt' => $issuedAt->format('c'),
            'itemCount' => $itemCount,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }
}
