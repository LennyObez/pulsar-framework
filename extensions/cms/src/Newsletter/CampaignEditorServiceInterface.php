<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Service interface for newsletter campaign management.
 *
 * Handles CRUD operations, scheduling, cancellation, recipient counting,
 * and test sends for newsletter campaigns.
 *
 * @psalm-api Public binding contract; implemented by CampaignEditorService
 *            and consumed by admin newsletter controllers.
 */
#[Api(since: '1.0.0')]
interface CampaignEditorServiceInterface
{
    /**
     * Create a new draft campaign.
     */
    public function create(
        string $subject,
        string $bodyHtml,
        string $locale,
        ?string $bodyText = null,
        ?string $tenantId = null,
        ?string $createdBy = null,
    ): NewsletterCampaign;

    /**
     * Update a draft campaign's content.
     *
     * @throws CmsException If the campaign is not found or is not in Draft status
     */
    public function update(
        string $campaignId,
        string $subject,
        string $bodyHtml,
        ?string $bodyText,
        string $locale,
    ): NewsletterCampaign;

    /**
     * Delete a campaign (only Draft or Cancelled campaigns can be deleted).
     *
     * @throws CmsException If the campaign is not found or cannot be deleted
     */
    public function delete(string $campaignId): void;

    /**
     * Schedule a campaign for a future send time.
     *
     * @throws CmsException If the campaign is not found or is not in Draft status
     */
    public function schedule(string $campaignId, DateTimeImmutable $scheduledAt): NewsletterCampaign;

    /**
     * Cancel a scheduled campaign.
     *
     * @throws CmsException If the campaign is not found or is not in Scheduled status
     */
    public function cancel(string $campaignId): NewsletterCampaign;

    /**
     * Get the count of confirmed subscribers matching the campaign's locale and tenant.
     */
    public function getRecipientCount(string $campaignId): int;

    /**
     * Send a test email of the campaign to a specific email address.
     *
     * @throws CmsException If the campaign is not found
     */
    public function sendTest(string $campaignId, string $testEmail): void;
}
