<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\Invoice;
use Pulsar\Extension\Cms\Commerce\InvoiceRepositoryInterface;
use Pulsar\Extension\Cms\Content\DataClassification;

use function sprintf;

/**
 * Database-backed invoice repository with sequential numbering.
 *
 * @psalm-api Bound to InvoiceRepositoryInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use InvoiceRepositoryInterface for public API')]
final readonly class DbInvoiceRepository implements InvoiceRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_invoices WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_ORDER = <<<'SQL'
        SELECT * FROM cms_invoices WHERE order_id = :order_id LIMIT 1
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'order_id', 'invoice_number', 'issued_at', 'due_at',
        'pdf_storage_path', 'pdf_hash', 'evidence_hash', 'data_classification',
    ];

    private const array UPSERT_UPDATE = ['pdf_storage_path', 'pdf_hash', 'evidence_hash'];

    private const string SQL_COUNT = <<<'SQL'
        SELECT COUNT(*) AS cnt FROM cms_invoices
        SQL;

    public function __construct(
        private ConnectionInterface $db,
        private ?string $tenantId = null,
    ) {}

    public function findById(string $id): ?Invoice
    {
        $row = $this->db->query(self::SQL_FIND_BY_ID, ['id' => $id])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByOrder(string $orderId): ?Invoice
    {
        $row = $this->db->query(self::SQL_FIND_BY_ORDER, ['order_id' => $orderId])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function save(Invoice $invoice): void
    {
        $sql = UpsertBuilder::compile(
            $this->db->driver(),
            'cms_invoices',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->db->execute($sql, [
            'id' => $invoice->id,
            'order_id' => $invoice->orderId,
            'invoice_number' => $invoice->invoiceNumber,
            'issued_at' => $invoice->issuedAt->format('c'),
            'due_at' => $invoice->dueAt->format('c'),
            'pdf_storage_path' => $invoice->pdfStoragePath,
            'pdf_hash' => $invoice->pdfHash,
            'evidence_hash' => $invoice->evidenceHash,
            'data_classification' => $invoice->dataClassification->value,
        ]);
    }

    public function nextInvoiceNumber(?string $tenantId = null): string
    {
        $sql = self::SQL_COUNT;
        $bindings = [];
        $effectiveTenantId = $tenantId ?? $this->tenantId;

        if ($effectiveTenantId !== null) {
            // Join through orders to scope invoices by tenant
            $sql = 'SELECT COUNT(*) AS cnt FROM cms_invoices i INNER JOIN cms_orders o ON o.id = i.order_id WHERE o.tenant_id = :tenant_id';
            $bindings['tenant_id'] = $effectiveTenantId;
        }

        $count = $this->db->query($sql, $bindings)->first()?->getInt('cnt') ?? 0;
        $year = new DateTimeImmutable()->format('Y');

        return sprintf('INV-%s-%06d', $year, $count + 1);
    }

    private static function hydrate(Row $row): Invoice
    {
        return new Invoice(
            id: $row->getString('id'),
            orderId: $row->getString('order_id'),
            invoiceNumber: $row->getString('invoice_number'),
            issuedAt: new DateTimeImmutable($row->getString('issued_at')),
            dueAt: new DateTimeImmutable($row->getString('due_at')),
            pdfStoragePath: $row->getNullableString('pdf_storage_path'),
            pdfHash: $row->getNullableString('pdf_hash'),
            evidenceHash: $row->getNullableString('evidence_hash'),
            dataClassification: DataClassification::from($row->getString('data_classification')),
        );
    }
}
