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

use function is_int;
use function is_string;

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
            id: $this->str('id'),
            siteId: $this->str('site_id'),
            visitorId: $this->str('visitor_id'),
            sessionId: $this->str('session_id'),
            pathname: $this->str('pathname', '/'),
            referrerSource: $this->str('referrer_source'),
            utmSource: $this->str('utm_source'),
            utmMedium: $this->str('utm_medium'),
            utmCampaign: $this->str('utm_campaign'),
            utmTerm: $this->str('utm_term'),
            utmContent: $this->str('utm_content'),
            countryCode: $this->str('country_code'),
            deviceType: DeviceType::tryFrom($this->str('device_type')) ?? DeviceType::Unknown,
            browser: $this->str('browser'),
            os: $this->str('os'),
            screenWidth: $this->int('screen_width'),
            isBounce: (bool) ($this->data['is_bounce'] ?? true),
            createdAt: new DateTimeImmutable($this->str('created_at', 'now')),
        );

        $this->repository->insert($pageView);
    }

    private function str(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    private function int(string $key, int $default = 0): int
    {
        $value = $this->data[$key] ?? null;

        return is_int($value) ? $value : $default;
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
