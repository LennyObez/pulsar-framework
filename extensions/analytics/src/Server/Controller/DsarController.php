<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\VisitorSaltStoreInterface;
use Pulsar\Extension\Analytics\Domain\VisitorId;
use Pulsar\Extension\Analytics\Dsar\AnalyticsDsarCollector;
use Pulsar\Extension\Analytics\Dsar\AnalyticsDsarEraser;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Http\Message\Response;

use function count;
use function is_string;

/**
 * DSAR (Data Subject Access Request) controller for analytics data.
 *
 * Provides authenticated endpoints for users to:
 * - Request a copy of their analytics data (GDPR Article 15)
 * - Request erasure of their analytics data (GDPR Article 17)
 *
 * Both endpoints require authentication and compute the user's visitor ID
 * from their IP and user agent using the same hashing approach as the
 * analytics tracker, so the correct records are matched.
 */
#[Internal(reason: 'Analytics DSAR API controller')]
final readonly class DsarController
{
    public function __construct(
        private AnalyticsDsarCollector $collector,
        private AnalyticsDsarEraser $eraser,
        private AnalyticsKeyManager $keyManager,
        private VisitorSaltStoreInterface $saltStore,
    ) {}

    /**
     * Handle a data subject access request.
     *
     * POST /plsr/dsar/request
     *
     * Returns a JSON response containing all analytics data associated
     * with the requesting user's visitor identity.
     */
    public function request(ServerRequestInterface $request): Response
    {
        $visitorId = $this->resolveVisitorId($request);

        $dataSet = $this->collector->collect($visitorId);

        return Response::json([
            'source' => $dataSet->sourceName,
            'category' => $dataSet->category,
            'record_count' => count($dataSet->records),
            'records' => $dataSet->records,
        ]);
    }

    /**
     * Handle a right to erasure request.
     *
     * POST /plsr/dsar/erase
     *
     * Deletes all analytics data associated with the requesting user's
     * visitor identity and returns the count of deleted records.
     */
    public function erase(ServerRequestInterface $request): Response
    {
        $visitorId = $this->resolveVisitorId($request);

        $totalDeleted = $this->eraser->erase($visitorId);

        return Response::json([
            'status' => 'erased',
            'records_deleted' => $totalDeleted,
        ]);
    }

    /**
     * Compute the visitor ID hash for the authenticated user.
     *
     * Uses the same VisitorId generation as the tracker so the correct records
     * match, keyed by today's disposable salt. This resolves TODAY's identity.
     *
     * By design it cannot reach further back: once a past day's salt has been
     * purged (forward secrecy), that day's hashes are unrecomputable, so the
     * data is irreversibly anonymized and out of DSAR scope under GDPR Recital
     * 26. Requests within the salt-retention window are covered; older data no
     * longer identifies anyone and therefore carries no access/erasure duty.
     */
    private function resolveVisitorId(ServerRequestInterface $request): string
    {
        /** @var mixed $rawIp */
        $rawIp = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip = is_string($rawIp) ? $rawIp : '0.0.0.0';
        $userAgent = $request->getHeaderLine('User-Agent');
        $dayNumber = $this->keyManager->utcDayNumber();
        $salt = $this->saltStore->saltForDay($dayNumber);

        $visitorId = VisitorId::generate($ip, $userAgent, $salt, $dayNumber);

        return $visitorId->toString();
    }
}
