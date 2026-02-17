<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Mail\Address;
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Notification\Channel\MailChannel;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;

#[CoversClass(MailChannel::class)]
final class MailChannelTest extends TestCase
{
    #[Test]
    public function it_sends_via_mail_manager(): void
    {
        $mailManager = $this->createMock(MailManagerInterface::class);
        $mailManager->expects(self::once())
            ->method('send')
            ->with(self::isInstanceOf(Mailable::class))
            ->willReturn('msg-001');

        $channel = new MailChannel($mailManager);

        $notifiable = $this->createNotifiable('user@test.com');
        $notification = $this->createMailNotification();

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function it_routes_to_notifiable_email(): void
    {
        $sentMailable = null;
        $mailManager = $this->createMock(MailManagerInterface::class);
        $mailManager->expects(self::once())
            ->method('send')
            ->with(self::callback(function (Mailable $mailable) use (&$sentMailable): bool {
                $sentMailable = $mailable;
                return true;
            }))
            ->willReturn('msg-002');

        $channel = new MailChannel($mailManager);

        $notifiable = $this->createNotifiable('routed@test.com');
        $notification = $this->createMailNotification();

        $channel->send($notifiable, $notification);

        // Build the mailable to verify routing was applied
        self::assertNotNull($sentMailable);
        $message = $sentMailable->build(new Address('default@test.com'));
        self::assertSame('routed@test.com', $message->to[0]->email);
    }

    #[Test]
    public function it_wraps_mail_exceptions_in_notification_exception(): void
    {
        $mailManager = $this->createStub(MailManagerInterface::class);
        $mailManager->method('send')
            ->willThrowException(MailException::sendFailed('Transport error'));

        $channel = new MailChannel($mailManager);

        $notifiable = $this->createNotifiable('user@test.com');
        $notification = $this->createMailNotification();

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('delivery failed');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function it_reports_mail_channel_name(): void
    {
        $mailManager = $this->createStub(MailManagerInterface::class);
        $channel = new MailChannel($mailManager);

        self::assertSame(NotificationChannelType::Mail->value, $channel->name());
    }

    private function createNotifiable(string $email): NotifiableInterface
    {
        return new class ($email) implements NotifiableInterface {
            public function __construct(private readonly string $email) {}

            public function routeNotificationFor(string $channel): mixed
            {
                return $this->email;
            }

            public function getNotifiableId(): string
            {
                return 'user-1';
            }

            public function preferredLocale(): ?string
            {
                return null;
            }
        };
    }

    private function createMailNotification(): Notification
    {
        return new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(NotifiableInterface $notifiable): Mailable
            {
                return new class extends Mailable {
                    public function envelope(): Envelope
                    {
                        return new Envelope(subject: 'Test Notification');
                    }

                    public function content(): Content
                    {
                        return new Content(html: '<p>Notification body</p>');
                    }
                };
            }
        };
    }
}
