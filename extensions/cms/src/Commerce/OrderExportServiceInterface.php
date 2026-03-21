<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Service interface for exporting order data in various formats.
 * @api
 */
#[Api(since: '1.0.0')]
interface OrderExportServiceInterface
{
    /**
     * Export orders matching filters as CSV content.
     *
     * @param array<string, mixed> $filters Filtering criteria (dateFrom, dateTo, status, tenantId)
     */
    public function exportCsv(array $filters): string;

    /**
     * Export orders matching filters as JSON content.
     *
     * @param array<string, mixed> $filters Filtering criteria (dateFrom, dateTo, status, tenantId)
     * @param bool $includePii Whether to include personal data (addresses, emails)
     */
    public function exportJson(array $filters, bool $includePii = false): string;
}
