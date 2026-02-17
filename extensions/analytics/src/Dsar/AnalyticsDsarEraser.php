<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Dsar;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * Handles GDPR Article 17 (right to erasure) for analytics data.
 *
 * Deletes all analytics records (page views, sessions, custom events)
 * associated with a visitor ID and produces an audit log entry
 * documenting the erasure for compliance evidence.
 */
#[Internal(reason: 'DSAR eraser; registered via service provider')]
final readonly class AnalyticsDsarEraser
{
    public function __construct(
        private PageViewRepositoryInterface $pageViewRepository,
        private SessionRepositoryInterface $sessionRepository,
        private EventRepositoryInterface $eventRepository,
        private AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Erase all analytics data for a visitor.
     *
     * Deletes page views, sessions, and custom events across all sites.
     * Logs the erasure event with total row counts for audit trail.
     *
     * @param string $visitorId The hashed visitor identifier
     *
     * @return int Total number of deleted rows across all repositories
     */
    public function erase(string $visitorId): int
    {
        $pageViewsDeleted = $this->pageViewRepository->deleteByVisitorId($visitorId);
        $sessionsDeleted = $this->sessionRepository->deleteByVisitorId($visitorId);
        $eventsDeleted = $this->eventRepository->deleteByVisitorId($visitorId);

        $totalDeleted = $pageViewsDeleted + $sessionsDeleted + $eventsDeleted;

        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: null,
            action: 'analytics.dsar.erasure',
            resource: 'analytics_visitor:' . $visitorId,
            metadata: [
                'page_views_deleted' => $pageViewsDeleted,
                'sessions_deleted' => $sessionsDeleted,
                'events_deleted' => $eventsDeleted,
                'total_deleted' => $totalDeleted,
            ],
        );

        return $totalDeleted;
    }
}
