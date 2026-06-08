<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
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
     * Uses the same VisitorId generation as the analytics tracker to ensure
     * we match the correct records. The day number is set to the current
     * UTC day to find today's records; historical data uses the same visitor
     * hash since the key is stable (daily rotation is in the hash input).
     */
    private function resolveVisitorId(ServerRequestInterface $request): string
    {
        /** @var mixed $rawIp */
        $rawIp = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip = is_string($rawIp) ? $rawIp : '0.0.0.0';
        $userAgent = $request->getHeaderLine('User-Agent');
        $key = $this->keyManager->visitorKey();
        $dayNumber = $this->keyManager->utcDayNumber();

        $visitorId = VisitorId::generate($ip, $userAgent, $key, $dayNumber);

        return $visitorId->toString();
    }
}
