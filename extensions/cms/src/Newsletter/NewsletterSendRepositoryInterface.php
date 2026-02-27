<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for newsletter send records.
 *
 * @psalm-api Public binding contract; implemented by DbNewsletterSendRepository
 *            and consumed by dispatch jobs.
 */
#[Api(since: '1.0.0')]
interface NewsletterSendRepositoryInterface
{
    public function save(NewsletterSend $send): void;

    /**
     * Persist multiple send records in a single batch operation.
     *
     * @param list<NewsletterSend> $sends
     */
    public function saveBatch(array $sends): void;

    /**
     * @return PaginationResult<NewsletterSend>
     */
    public function findByCampaignId(
        string $campaignId,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult;

    /**
     * @return list<NewsletterSend>
     */
    public function findBySubscriberId(string $subscriberId): array;

    public function updateStatus(string $sendId, SendStatus $status, ?string $bounceReason = null): void;

    public function countByCampaignAndStatus(string $campaignId, SendStatus $status): int;
}
