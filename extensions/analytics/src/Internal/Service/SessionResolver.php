<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\Session;
use Pulsar\Extension\Analytics\Domain\SessionId;
use Pulsar\Extension\Analytics\Domain\VisitorId;

/**
 * Resolves visitor activity into sessions using a 30-minute inactivity window.
 *
 * Handles midnight boundary gracefully: when a page view arrives in the first
 * 30 minutes of a new UTC day, the resolver also checks for sessions using
 * yesterday's visitor hash to maintain session continuity across day boundaries.
 *
 * New sessions are persisted immediately via the repository to prevent
 * duplicate session creation under concurrent requests (TOCTOU).
 */
#[Internal(reason: 'Session resolution internals — use via service binding')]
final readonly class SessionResolver
{
    /**
     * Session inactivity timeout in minutes.
     */
    private const int INACTIVITY_MINUTES = 30;

    public function __construct(
        private AnalyticsConfig $config,
        private SessionRepositoryInterface $sessionRepository,
    ) {}

    /**
     * Resolve the current session for a visitor.
     *
     * If an active session (with activity within the last 30 minutes) exists,
     * it is continued and updated. Otherwise, a new session is created and
     * persisted immediately to prevent TOCTOU race conditions.
     *
     * @param VisitorId $visitorId Today's visitor identifier
     * @param DateTimeImmutable $now Current timestamp
     * @param string $entryPage The page being viewed
     * @param string $siteId The site identifier
     * @param string $visitorKey Today's visitor key (for session ID derivation)
     * @param VisitorId|null $yesterdayVisitorId Yesterday's visitor identifier (for midnight grace)
     */
    public function resolve(
        VisitorId $visitorId,
        DateTimeImmutable $now,
        string $entryPage,
        string $siteId,
        string $visitorKey,
        ?VisitorId $yesterdayVisitorId = null,
    ): Session {
        $since = $now->modify('-' . self::INACTIVITY_MINUTES . ' minutes');

        // Try to find an active session for today's visitor
        $session = $this->sessionRepository->findActiveByVisitor($siteId, $visitorId->hash, $since);

        if ($session !== null) {
            $updated = $session->withPageView($entryPage, $now);
            $this->sessionRepository->update($updated);

            return $updated;
        }

        // Midnight grace period: check for sessions using yesterday's visitor hash
        if ($yesterdayVisitorId !== null && $this->isWithinMidnightGrace($now)) {
            $session = $this->sessionRepository->findActiveByVisitor(
                $siteId,
                $yesterdayVisitorId->hash,
                $since,
            );

            if ($session !== null) {
                $updated = $session->withPageView($entryPage, $now);
                $this->sessionRepository->update($updated);

                return $updated;
            }
        }

        // No active session found — create and persist immediately to prevent TOCTOU
        $sessionId = SessionId::generate($visitorId, $now->getTimestamp(), $visitorKey);

        $newSession = new Session(
            id: $sessionId->hash,
            siteId: $siteId,
            visitorId: $visitorId->hash,
            sessionId: $sessionId->hash,
            entryPage: $entryPage,
            exitPage: $entryPage,
            pageCount: 1,
            durationSeconds: 0,
            isBounce: true,
            startedAt: $now,
            endedAt: $now,
        );

        $this->sessionRepository->save($newSession);

        return $newSession;
    }

    /**
     * Check if the current time falls within the midnight grace period.
     *
     * Returns true if we are within the first 30 minutes of the UTC day,
     * allowing session continuity across the daily key rotation boundary.
     */
    private function isWithinMidnightGrace(DateTimeImmutable $now): bool
    {
        $minuteOfDay = (int) $now->format('G') * 60 + (int) $now->format('i');

        return $minuteOfDay < self::INACTIVITY_MINUTES;
    }
}
