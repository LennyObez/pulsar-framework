<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Invoice;
use Pulsar\Extension\Cms\Content\DataClassification;

#[CoversClass(Invoice::class)]
final class InvoiceTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $issued = new DateTimeImmutable('2026-03-01T00:00:00Z');
        $due = new DateTimeImmutable('2026-04-01T00:00:00Z');

        $invoice = new Invoice(
            id: 'inv-001',
            orderId: 'ord-001',
            invoiceNumber: 'INV-2026-0001',
            issuedAt: $issued,
            dueAt: $due,
            pdfStoragePath: '/storage/invoices/inv-001.pdf',
            pdfHash: 'sha256abc',
            evidenceHash: 'sha256evidence',
            dataClassification: DataClassification::Confidential,
        );

        self::assertSame('inv-001', $invoice->id);
        self::assertSame('ord-001', $invoice->orderId);
        self::assertSame('INV-2026-0001', $invoice->invoiceNumber);
        self::assertSame($issued, $invoice->issuedAt);
        self::assertSame($due, $invoice->dueAt);
        self::assertSame('/storage/invoices/inv-001.pdf', $invoice->pdfStoragePath);
        self::assertSame('sha256abc', $invoice->pdfHash);
        self::assertSame('sha256evidence', $invoice->evidenceHash);
        self::assertSame(DataClassification::Confidential, $invoice->dataClassification);
    }

    #[Test]
    public function pdfFieldsAreNullable(): void
    {
        $invoice = new Invoice(
            id: 'inv-002',
            orderId: 'ord-002',
            invoiceNumber: 'INV-2026-0002',
            issuedAt: new DateTimeImmutable(),
            dueAt: new DateTimeImmutable('+30 days'),
            pdfStoragePath: null,
            pdfHash: null,
            evidenceHash: null,
            dataClassification: DataClassification::Internal,
        );

        self::assertNull($invoice->pdfStoragePath);
        self::assertNull($invoice->pdfHash);
        self::assertNull($invoice->evidenceHash);
    }
}
