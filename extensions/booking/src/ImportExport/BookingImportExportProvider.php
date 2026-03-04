<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\ImportExport;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ExportResult;
use Pulsar\ImportExport\ImportExportProviderInterface;
use Pulsar\ImportExport\ImportRequest;
use Pulsar\ImportExport\ImportResult;

use function hash;
use function in_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Import/export provider for booking data.
 */
#[Internal]
final class BookingImportExportProvider implements ImportExportProviderInterface
{
    #[Override]
    public function name(): string
    {
        return 'booking';
    }

    #[Override]
    public function label(): string
    {
        return 'Booking & Appointments';
    }

    #[Override]
    public function supportedFormats(): array
    {
        return ['json', 'csv'];
    }

    #[Override]
    public function export(ExportRequest $request): ExportResult
    {
        $data = [];
        $entityTypes = [];

        if ($request->entityTypes === [] || in_array('appointments', $request->entityTypes, true)) {
            $data['appointments'] = [];
            $entityTypes[] = 'appointments';
        }

        if ($request->entityTypes === [] || in_array('services', $request->entityTypes, true)) {
            $data['services'] = [];
            $entityTypes[] = 'services';
        }

        if ($request->entityTypes === [] || in_array('categories', $request->entityTypes, true)) {
            $data['categories'] = [];
            $entityTypes[] = 'categories';
        }

        $serialized = json_encode($data, JSON_THROW_ON_ERROR);

        return new ExportResult(
            providerName: 'booking',
            data: $data,
            format: $request->format,
            evidenceHash: hash('blake2b', $serialized),
            entityTypes: $entityTypes,
            createdAt: new DateTimeImmutable(),
        );
    }

    #[Override]
    public function import(ImportRequest $request): ImportResult
    {
        return new ImportResult(
            providerName: 'booking',
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: $request->dryRun,
        );
    }

    #[Override]
    public function schema(): array
    {
        return [
            'appointments' => [
                'id' => 'string',
                'booking_number' => 'string (BKG-YYYY-NNNNNN)',
                'service_id' => 'string',
                'customer_id' => 'string',
                'customer_name' => 'string',
                'customer_email' => 'string (email)',
                'customer_phone' => 'string (E.164)',
                'status' => 'string (requested|confirmed|deposit_paid|reminded|in_progress|completed|cancelled|no_show|rescheduled)',
                'scheduled_at' => 'string (ISO 8601)',
                'duration' => 'int (minutes)',
                'deposit_amount' => 'int (minor units, nullable)',
                'deposit_currency' => 'string (ISO 4217, nullable)',
                'deposit_paid' => 'bool',
                'notes' => 'string',
            ],
            'services' => [
                'id' => 'string',
                'name' => 'string',
                'description' => 'string',
                'duration' => 'int (minutes)',
                'base_price' => 'int (minor units)',
                'base_currency' => 'string (ISO 4217)',
                'deposit_percent' => 'int (0-100)',
                'category_id' => 'string (nullable)',
                'active' => 'bool',
            ],
            'categories' => [
                'id' => 'string',
                'name' => 'string',
                'slug' => 'string',
                'sort_order' => 'int',
            ],
        ];
    }
}
