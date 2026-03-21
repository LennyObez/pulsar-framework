<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for newsletter subscribers.
 *
 * @psalm-api Public binding contract; implemented by DbNewsletterSubscriberRepository
 *            and consumed by NewsletterSubscriptionService.
 * @api
 */
#[Api(since: '1.0.0')]
interface NewsletterSubscriberRepositoryInterface
{
    public function save(NewsletterSubscriber $subscriber): void;

    public function findById(string $id): ?NewsletterSubscriber;

    public function findByEmail(string $email, ?string $tenantId = null): ?NewsletterSubscriber;

    /**
     * @return PaginationResult<NewsletterSubscriber>
     */
    public function findByStatus(
        SubscriberStatus $status,
        ?string $tenantId = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult;

    /**
     * Find all confirmed subscribers, optionally filtered by locale.
     *
     * @return list<NewsletterSubscriber>
     */
    public function findAllConfirmed(?string $locale = null, ?string $tenantId = null): array;

    public function countByStatus(SubscriberStatus $status, ?string $tenantId = null): int;

    public function delete(NewsletterSubscriber $subscriber): void;
}
