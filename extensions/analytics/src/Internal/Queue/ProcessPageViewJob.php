<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Queue;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\DeviceType;
use Pulsar\Extension\Analytics\Domain\PageView;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\QueueableInterface;

/**
 * Optional queue mode: processes page view inserts asynchronously.
 *
 * Used when collection.driver = 'queue' for deployments with high DB latency.
 */
#[Internal(reason: 'Queue job for async page view processing')]
final readonly class ProcessPageViewJob implements QueueableInterface
{
    public function __construct(
        private PageViewRepositoryInterface $repository,
        /** @var array<string, mixed> */
        private array $data = [],
    ) {}

    #[Override]
    public function handle(JobContext $context): void
    {
        $pageView = new PageView(
            id: (string) ($this->data['id'] ?? ''),
            siteId: (string) ($this->data['site_id'] ?? ''),
            visitorId: (string) ($this->data['visitor_id'] ?? ''),
            sessionId: (string) ($this->data['session_id'] ?? ''),
            pathname: (string) ($this->data['pathname'] ?? '/'),
            referrerSource: (string) ($this->data['referrer_source'] ?? ''),
            utmSource: (string) ($this->data['utm_source'] ?? ''),
            utmMedium: (string) ($this->data['utm_medium'] ?? ''),
            utmCampaign: (string) ($this->data['utm_campaign'] ?? ''),
            utmTerm: (string) ($this->data['utm_term'] ?? ''),
            utmContent: (string) ($this->data['utm_content'] ?? ''),
            countryCode: (string) ($this->data['country_code'] ?? ''),
            deviceType: DeviceType::tryFrom((string) ($this->data['device_type'] ?? '')) ?? DeviceType::Unknown,
            browser: (string) ($this->data['browser'] ?? ''),
            os: (string) ($this->data['os'] ?? ''),
            screenWidth: (int) ($this->data['screen_width'] ?? 0),
            isBounce: (bool) ($this->data['is_bounce'] ?? true),
            createdAt: new DateTimeImmutable((string) ($this->data['created_at'] ?? 'now')),
        );

        $this->repository->insert($pageView);
    }

    #[Override]
    public function queue(): string
    {
        return 'analytics';
    }

    #[Override]
    public function maxAttempts(): int
    {
        return 3;
    }

    #[Override]
    public function timeout(): int
    {
        return 10;
    }
}
