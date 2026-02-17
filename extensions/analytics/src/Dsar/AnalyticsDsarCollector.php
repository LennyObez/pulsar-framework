<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Dsar;

use Override;
use Pulsar\Api\Internal;
use Pulsar\DataProtection\Dsar\DsarCollectorInterface;
use Pulsar\DataProtection\Dsar\DsarDataSet;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;

use function array_merge;

/**
 * DSAR (Data Subject Access Request) collector for analytics data.
 *
 * Gathers all analytics data associated with a visitor ID: page views,
 * sessions, and custom events. Returns a DsarDataSet suitable for
 * inclusion in the data subject's GDPR Article 15 response package.
 *
 * The subjectId is expected to be a visitor hash consistent with the
 * privacy-preserving hashing used throughout the analytics extension.
 */
#[Internal(reason: 'DSAR collector; registered via service provider')]
final readonly class AnalyticsDsarCollector implements DsarCollectorInterface
{
    public function __construct(
        private PageViewRepositoryInterface $pageViewRepository,
        private SessionRepositoryInterface $sessionRepository,
        private EventRepositoryInterface $eventRepository,
    ) {}

    #[Override]
    public function sourceName(): string
    {
        return 'analytics';
    }

    #[Override]
    public function collect(string $subjectId): DsarDataSet
    {
        $pageViews = $this->pageViewRepository->findByVisitorId($subjectId);
        $sessions = $this->sessionRepository->findByVisitorId($subjectId);
        $events = $this->eventRepository->findByVisitorId($subjectId);

        if ($pageViews === [] && $sessions === [] && $events === []) {
            return DsarDataSet::empty('analytics', 'analytics');
        }

        $records = array_merge(
            $this->serializePageViews($pageViews),
            $this->serializeSessions($sessions),
            $this->serializeEvents($events),
        );

        return new DsarDataSet(
            sourceName: 'analytics',
            category: 'analytics',
            records: $records,
        );
    }

    /**
     * @param list<\Pulsar\Extension\Analytics\Domain\PageView> $pageViews
     * @return list<array<string, mixed>>
     */
    private function serializePageViews(array $pageViews): array
    {
        $records = [];

        foreach ($pageViews as $pv) {
            $records[] = [
                'type' => 'page_view',
                'id' => $pv->id,
                'site_id' => $pv->siteId,
                'pathname' => $pv->pathname,
                'referrer_source' => $pv->referrerSource,
                'country_code' => $pv->countryCode,
                'device_type' => $pv->deviceType->value,
                'browser' => $pv->browser,
                'os' => $pv->os,
                'screen_width' => $pv->screenWidth,
                'is_bounce' => $pv->isBounce,
                'created_at' => $pv->createdAt->format('c'),
            ];
        }

        return $records;
    }

    /**
     * @param list<\Pulsar\Extension\Analytics\Domain\Session> $sessions
     * @return list<array<string, mixed>>
     */
    private function serializeSessions(array $sessions): array
    {
        $records = [];

        foreach ($sessions as $session) {
            $records[] = [
                'type' => 'session',
                'id' => $session->id,
                'site_id' => $session->siteId,
                'entry_page' => $session->entryPage,
                'exit_page' => $session->exitPage,
                'page_count' => $session->pageCount,
                'duration_seconds' => $session->durationSeconds,
                'is_bounce' => $session->isBounce,
                'started_at' => $session->startedAt->format('c'),
                'ended_at' => $session->endedAt->format('c'),
            ];
        }

        return $records;
    }

    /**
     * @param list<\Pulsar\Extension\Analytics\Domain\CustomEvent> $events
     * @return list<array<string, mixed>>
     */
    private function serializeEvents(array $events): array
    {
        $records = [];

        foreach ($events as $event) {
            $records[] = [
                'type' => 'custom_event',
                'id' => $event->id,
                'site_id' => $event->siteId,
                'event_name' => $event->eventName,
                'event_props' => $event->eventProps,
                'revenue_value' => $event->revenueValue,
                'pathname' => $event->pathname,
                'created_at' => $event->createdAt->format('c'),
            ];
        }

        return $records;
    }
}
