<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Access;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\DataAct\Portability\DataPortabilityService;
use Pulsar\Extension\DataAct\Portability\ExportRequest;

/**
 * Controller for data access and export operations per Data Act Art. 4-5.
 *
 * Provides endpoints for users to request data exports, check export
 * status, and manage their portability rights.
 */
#[Api(since: '1.0.0')]
final readonly class DataAccessController
{
    public function __construct(
        private DataPortabilityService $portability,
        private ThirdPartyAccessPolicy $accessPolicy,
    ) {}

    /**
     * Submit a new data export request.
     *
     * @param string       $userId  The requesting user
     * @param string       $format  Desired export format
     * @param list<string> $scopes  Data scopes to include
     */
    #[NoDiscard]
    public function requestExport(
        string $userId,
        string $format = 'json',
        array $scopes = [],
    ): ExportRequest {
        return $this->portability->requestExport($userId, $format, $scopes);
    }

    /**
     * Get the current status of an export request.
     */
    #[NoDiscard]
    public function exportStatus(string $requestId): ?ExportRequest
    {
        return $this->portability->getExportStatus($requestId);
    }

    /**
     * Request data access on behalf of a third party.
     *
     * The request is validated against the access policy to ensure
     * compliance with Art. 6 (third-party data access conditions).
     */
    #[NoDiscard]
    public function requestThirdPartyAccess(
        string $requestingParty,
        string $dataSubjectId,
        string $purpose,
    ): ThirdPartyAccessResult {
        return $this->accessPolicy->evaluate($requestingParty, $dataSubjectId, $purpose);
    }
}
