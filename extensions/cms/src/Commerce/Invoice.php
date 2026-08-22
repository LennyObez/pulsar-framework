<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\DataClassification;

/**
 * Invoice generated for a completed order, with tamper-evident hashing.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Invoice
{
    /**
     * @param string $id UUIDv7
     * @param string $orderId UUIDv7 of the associated order
     * @param string $invoiceNumber Sequential human-readable invoice reference
     * @param DateTimeImmutable $issuedAt When the invoice was generated
     * @param DateTimeImmutable $dueAt Payment due date
     * @param string|null $pdfStoragePath Filesystem path to the generated PDF
     * @param string|null $pdfHash SHA-256 hash of the PDF file for tamper detection
     * @param string|null $evidenceHash SHA-256 hash of the invoice data for audit trail
     * @param DataClassification $dataClassification Data classification for compliance
     */
    public function __construct(
        public string $id,
        public string $orderId,
        public string $invoiceNumber,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $dueAt,
        public ?string $pdfStoragePath,
        public ?string $pdfHash,
        public ?string $evidenceHash,
        public DataClassification $dataClassification,
    ) {}
}
