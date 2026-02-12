<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Newsletter;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Newsletter\CampaignEditorServiceInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaign;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaignRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Mail\Address;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Mail\Message;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function count;

/**
 * Campaign editor service for newsletter campaign management.
 *
 * Enforces status-based constraints: only draft campaigns can be edited,
 * only draft campaigns can be scheduled, only scheduled campaigns can
 * be cancelled, and only draft or cancelled campaigns can be deleted.
 */
#[Internal(reason: 'Use CampaignEditorServiceInterface for public API')]
final readonly class CampaignEditorService implements CampaignEditorServiceInterface
{
    public function __construct(
        private NewsletterCampaignRepositoryInterface $campaignRepository,
        private NewsletterSubscriberRepositoryInterface $subscriberRepository,
        private MailManagerInterface $mailManager,
        private AuditLoggerInterface $auditLogger,
    ) {}

    public function create(
        string $subject,
        string $bodyHtml,
        string $locale,
        ?string $bodyText = null,
        ?string $tenantId = null,
        ?string $createdBy = null,
    ): NewsletterCampaign {
        $campaign = NewsletterCampaign::create(
            id: UuidGenerator::v7(),
            subject: $subject,
            bodyHtml: $bodyHtml,
            locale: $locale,
            bodyText: $bodyText,
            tenantId: $tenantId,
            createdBy: $createdBy,
        );

        $this->campaignRepository->save($campaign);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $createdBy,
            'cms.newsletter.campaign.created',
            "campaign:{$campaign->id}",
            ['subject' => $subject, 'locale' => $locale],
        );

        return $campaign;
    }

    public function update(
        string $campaignId,
        string $subject,
        string $bodyHtml,
        ?string $bodyText,
        string $locale,
    ): NewsletterCampaign {
        $campaign = $this->findOrFail($campaignId);

        if (!$campaign->isDraft()) {
            throw CmsException::campaignNotEditable($campaignId);
        }

        $updated = $campaign->update($subject, $bodyHtml, $bodyText, $locale);
        $this->campaignRepository->save($updated);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $campaign->createdBy,
            'cms.newsletter.campaign.updated',
            "campaign:{$campaignId}",
            ['subject' => $subject],
        );

        return $updated;
    }

    public function delete(string $campaignId): void
    {
        $campaign = $this->findOrFail($campaignId);

        if (!$campaign->isDraft() && !$campaign->isCancelled()) {
            throw CmsException::campaignNotDeletable($campaignId);
        }

        $this->campaignRepository->delete($campaign);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $campaign->createdBy,
            'cms.newsletter.campaign.deleted',
            "campaign:{$campaignId}",
            ['subject' => $campaign->subject],
        );
    }

    public function schedule(string $campaignId, DateTimeImmutable $scheduledAt): NewsletterCampaign
    {
        $campaign = $this->findOrFail($campaignId);

        if (!$campaign->isDraft()) {
            throw CmsException::campaignNotSchedulable($campaignId);
        }

        $scheduled = $campaign->schedule($scheduledAt);
        $this->campaignRepository->save($scheduled);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $campaign->createdBy,
            'cms.newsletter.campaign.scheduled',
            "campaign:{$campaignId}",
            ['scheduled_at' => $scheduledAt->format('c')],
        );

        return $scheduled;
    }

    public function cancel(string $campaignId): NewsletterCampaign
    {
        $campaign = $this->findOrFail($campaignId);

        if (!$campaign->isScheduled()) {
            throw CmsException::campaignNotCancellable($campaignId);
        }

        $cancelled = $campaign->cancel();
        $this->campaignRepository->save($cancelled);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $campaign->createdBy,
            'cms.newsletter.campaign.cancelled',
            "campaign:{$campaignId}",
            ['subject' => $campaign->subject],
        );

        return $cancelled;
    }

    public function getRecipientCount(string $campaignId): int
    {
        $campaign = $this->findOrFail($campaignId);

        $subscribers = $this->subscriberRepository->findAllConfirmed(
            $campaign->locale,
            $campaign->tenantId,
        );

        return count($subscribers);
    }

    public function sendTest(string $campaignId, string $testEmail): void
    {
        $campaign = $this->findOrFail($campaignId);

        $message = new Message(
            from: new Address('newsletter@example.com', 'Newsletter'),
            to: [new Address($testEmail)],
            subject: '[TEST] ' . $campaign->subject,
            htmlBody: $campaign->bodyHtml,
            textBody: $campaign->bodyText,
        );

        $this->mailManager->raw($message);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $campaign->createdBy,
            'cms.newsletter.campaign.test_sent',
            "campaign:{$campaignId}",
            ['test_email' => $testEmail],
        );
    }

    /**
     * @throws CmsException If the campaign is not found
     */
    private function findOrFail(string $campaignId): NewsletterCampaign
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw CmsException::campaignNotFound($campaignId);
        }

        return $campaign;
    }
}
