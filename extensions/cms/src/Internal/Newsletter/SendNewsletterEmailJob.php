<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Newsletter;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Newsletter\NewsletterSendRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\SendStatus;
use Pulsar\Mail\Address;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Mail\Message;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\QueueableInterface;
use Throwable;

/**
 * Queued job that sends a single newsletter email to one subscriber.
 *
 * This is an alternative to the batch approach in CampaignDispatchJob,
 * useful when campaigns need per-recipient queuing for better fault isolation.
 * Each job sends one email and updates the corresponding send record status.
 */
#[Internal(reason: 'Queue job; implementation detail')]
final readonly class SendNewsletterEmailJob implements QueueableInterface
{
    public function __construct(
        private string $subscriberEmail,
        private string $subject,
        private string $htmlBody,
        private ?string $textBody,
        private string $sendId,
        private NewsletterSendRepositoryInterface $sendRepository,
        private MailManagerInterface $mailManager,
    ) {}

    #[Override]
    public function handle(JobContext $context): void
    {
        try {
            $message = new Message(
                from: new Address('newsletter@example.com', 'Newsletter'),
                to: [new Address($this->subscriberEmail)],
                subject: $this->subject,
                htmlBody: $this->htmlBody,
                textBody: $this->textBody,
            );

            $this->mailManager->raw($message);

            $this->sendRepository->updateStatus($this->sendId, SendStatus::Sent);
        } catch (Throwable) {
            $this->sendRepository->updateStatus(
                $this->sendId,
                SendStatus::Failed,
                'Mail delivery failed',
            );
        }
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
        return 30;
    }
}
