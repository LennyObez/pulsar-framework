<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Newsletter;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Newsletter\CampaignStatus;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaignRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSend;
use Pulsar\Extension\Cms\Newsletter\NewsletterSendRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Mail\Address;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Mail\Message;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\QueueableInterface;

use function count;
use function str_replace;

/**
 * Queued job that dispatches a newsletter campaign to all matching subscribers.
 *
 * Creates NewsletterSend records for each confirmed subscriber matching the
 * campaign's locale and tenant, sends each email, and updates the send record
 * statuses. After all sends are processed, marks the campaign as Sent.
 */
#[Internal(reason: 'Queue job — implementation detail')]
final readonly class CampaignDispatchJob implements QueueableInterface
{
    public function __construct(
        private string $campaignId,
        private NewsletterCampaignRepositoryInterface $campaignRepository,
        private NewsletterSubscriberRepositoryInterface $subscriberRepository,
        private NewsletterSendRepositoryInterface $sendRepository,
        private MailManagerInterface $mailManager,
    ) {}

    #[Override]
    public function handle(JobContext $context): void
    {
        $campaign = $this->campaignRepository->findById($this->campaignId);

        if ($campaign === null) {
            return;
        }

        // Only dispatch campaigns in scheduled or draft (immediate send) status
        if ($campaign->status !== CampaignStatus::Scheduled && $campaign->status !== CampaignStatus::Draft) {
            return;
        }

        $subscribers = $this->subscriberRepository->findAllConfirmed(
            $campaign->locale,
            $campaign->tenantId,
        );

        $recipientCount = count($subscribers);

        // Transition campaign to sending
        $sending = $campaign->markSending($recipientCount);
        $this->campaignRepository->save($sending);

        // Create send records in batch
        $sends = [];

        foreach ($subscribers as $subscriber) {
            $sends[] = NewsletterSend::create(
                id: UuidGenerator::v7(),
                campaignId: $this->campaignId,
                subscriberId: $subscriber->id,
            );
        }

        $this->sendRepository->saveBatch($sends);

        // Send emails and update statuses
        foreach ($sends as $index => $send) {
            $subscriber = $subscribers[$index];

            $htmlBody = str_replace(
                ['{{email}}', '{{subscriber_id}}'],
                [$subscriber->email, $subscriber->id],
                $campaign->bodyHtml,
            );

            $textBody = $campaign->bodyText !== null
                ? str_replace(
                    ['{{email}}', '{{subscriber_id}}'],
                    [$subscriber->email, $subscriber->id],
                    $campaign->bodyText,
                )
                : null;

            $message = new Message(
                from: new Address('newsletter@example.com', 'Newsletter'),
                to: [new Address($subscriber->email)],
                subject: $campaign->subject,
                htmlBody: $htmlBody,
                textBody: $textBody,
            );

            $this->mailManager->raw($message);

            $sentSend = $send->markSent();
            $this->sendRepository->save($sentSend);
        }

        // Mark campaign as sent
        $sent = $sending->markSent();
        $this->campaignRepository->save($sent);
    }

    #[Override]
    public function queue(): string
    {
        return 'newsletter';
    }

    #[Override]
    public function maxAttempts(): int
    {
        return 3;
    }

    #[Override]
    public function timeout(): int
    {
        return 300;
    }
}
