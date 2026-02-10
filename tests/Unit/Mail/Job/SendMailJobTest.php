<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Job\SendMailJob;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Queue\JobContext;

#[CoversClass(SendMailJob::class)]
final class SendMailJobTest extends TestCase
{
    #[Test]
    public function handleSendsMailableViaManager(): void
    {
        $mailable = $this->createStub(Mailable::class);
        $mailManager = $this->createMock(MailManagerInterface::class);
        $mailManager->expects(self::once())
            ->method('send')
            ->with($mailable);

        $job = new SendMailJob($mailable, $mailManager);
        $context = $this->createStub(JobContext::class);

        $job->handle($context);
    }

    #[Test]
    public function defaultQueueIsMail(): void
    {
        $job = new SendMailJob(
            $this->createStub(Mailable::class),
            $this->createStub(MailManagerInterface::class),
        );

        self::assertSame('mail', $job->queue());
    }

    #[Test]
    public function customQueueNameIsRespected(): void
    {
        $job = new SendMailJob(
            $this->createStub(Mailable::class),
            $this->createStub(MailManagerInterface::class),
            queueName: 'priority-mail',
        );

        self::assertSame('priority-mail', $job->queue());
    }

    #[Test]
    public function defaultMaxAttemptsIsThree(): void
    {
        $job = new SendMailJob(
            $this->createStub(Mailable::class),
            $this->createStub(MailManagerInterface::class),
        );

        self::assertSame(3, $job->maxAttempts());
    }

    #[Test]
    public function customMaxAttempts(): void
    {
        $job = new SendMailJob(
            $this->createStub(Mailable::class),
            $this->createStub(MailManagerInterface::class),
            maxAttemptCount: 5,
        );

        self::assertSame(5, $job->maxAttempts());
    }

    #[Test]
    public function defaultTimeoutIs60Seconds(): void
    {
        $job = new SendMailJob(
            $this->createStub(Mailable::class),
            $this->createStub(MailManagerInterface::class),
        );

        self::assertSame(60, $job->timeout());
    }

    #[Test]
    public function customTimeout(): void
    {
        $job = new SendMailJob(
            $this->createStub(Mailable::class),
            $this->createStub(MailManagerInterface::class),
            timeoutSeconds: 120,
        );

        self::assertSame(120, $job->timeout());
    }
}
