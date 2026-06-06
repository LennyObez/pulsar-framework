<?php

declare(strict_types=1);

namespace Pulsar\Mail\Job;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\QueueableInterface;

/**
 * Queueable job that sends a Mailable through the mail manager.
 *
 * Used for asynchronous mail delivery via the queue system.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class SendMailJob implements QueueableInterface
{
    public function __construct(
        private Mailable $mailable,
        private MailManagerInterface $mailManager,
        private string $queueName = 'mail',
        private int $maxAttemptCount = 3,
        private int $timeoutSeconds = 60,
    ) {}

    #[Override]
    public function handle(JobContext $context): void
    {
        $this->mailManager->send($this->mailable);
    }

    #[Override]
    public function queue(): string
    {
        return $this->queueName;
    }

    #[Override]
    public function maxAttempts(): int
    {
        return $this->maxAttemptCount;
    }

    #[Override]
    public function timeout(): int
    {
        return $this->timeoutSeconds;
    }
}
