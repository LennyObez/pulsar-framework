<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for newsletter campaigns.
 *
 * @psalm-api Public binding contract; implemented by DbNewsletterCampaignRepository
 *            and consumed by CampaignEditorService and dispatch jobs.
 * @api
 */
#[Api(since: '1.0.0')]
interface NewsletterCampaignRepositoryInterface
{
    public function save(NewsletterCampaign $campaign): void;

    public function findById(string $id): ?NewsletterCampaign;

    /**
     * @return list<NewsletterCampaign>
     */
    public function findByStatus(CampaignStatus $status, ?string $tenantId = null): array;

    /**
     * @return PaginationResult<NewsletterCampaign>
     */
    public function findAllByTenant(
        ?string $tenantId = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult;

    public function delete(NewsletterCampaign $campaign): void;
}
