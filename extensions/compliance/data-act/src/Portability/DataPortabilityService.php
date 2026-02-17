<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Portability;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Data portability service as required by Data Act Articles 4-5.
 *
 * Manages data export requests, fulfillment tracking, and format
 * conversion. Data holders must make data available to users and
 * authorized third parties in a structured, commonly used, and
 * machine-readable format.
 */
#[Api(since: '1.0.0')]
abstract class DataPortabilityService
{
    /**
     * Submit a new data export request.
     *
     * Returns a request ID for tracking fulfillment status.
     *
     * @param string                $userId   User requesting the export
     * @param string                $format   Export format (json, csv, xml)
     * @param list<string>          $scopes   Data scopes to include in the export
     */
    #[NoDiscard]
    abstract public function requestExport(string $userId, string $format, array $scopes = []): ExportRequest;

    /**
     * Get the current status of an export request.
     */
    #[NoDiscard]
    abstract public function getExportStatus(string $requestId): ?ExportRequest;

    /**
     * Mark an export request as fulfilled with the given data payload.
     *
     * @param string $requestId  The export request identifier
     * @param string $dataPath   Path or URI to the exported data
     */
    abstract public function fulfillExport(string $requestId, string $dataPath): void;

    /**
     * List all export requests for a given user.
     *
     * @return list<ExportRequest>
     */
    #[NoDiscard]
    abstract public function listUserRequests(string $userId): array;

    /**
     * Cancel a pending export request.
     */
    abstract public function cancelExport(string $requestId): void;
}
