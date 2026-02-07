<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\MailConfig;
use Pulsar\Config\MailDriverType;
use Pulsar\Config\NotificationConfig;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\MailManager;
use Pulsar\Mail\Transport\ArrayTransport;
use Pulsar\Notification\Channel\MailChannel;
use Pulsar\Notification\Event\NotificationSent;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationManager;

#[CoversClass(NotificationManager::class)]
#[CoversClass(MailChannel::class)]
final class NotificationManagerIntegrationTest extends TestCase
{
    #[Test]
    public function it_delivers_notification_via_mail_channel_with_array_transport(): void
    {
        // Set up MailManager with ArrayTransport
        $mailConfig = new MailConfig(
            enabled: true,
            defaultDriver: MailDriverType::Array,
            defaultFromAddress: 'noreply@app.com',
            defaultFromName: 'Pulsar App',
        );

        $mailManager = new MailManager($mailConfig);

        // Set up NotificationManager with MailChannel
        $mailChannel = new MailChannel($mailManager);

        $dispatchedEvents = [];
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;
                return $event;
            });

        $notificationConfig = new NotificationConfig(enabled: true);
        $manager = new NotificationManager(
            $notificationConfig,
            ['mail' => $mailChannel],
            eventDispatcher: $dispatcher,
        );

        // Create notifiable and notification
        $notifiable = new class implements NotifiableInterface {
            public function routeNotificationFor(string $channel): mixed
            {
                return 'recipient@example.com';
            }

            public function getNotifiableId(): string
            {
                return 'user-integration-1';
            }

            public function preferredLocale(): string
            {
                return 'en';
            }
        };

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(NotifiableInterface $notifiable): Mailable
            {
                return new class extends Mailable {
                    public function envelope(): Envelope
                    {
                        return new Envelope(
                            subject: 'Welcome Notification',
                        );
                    }

                    public function content(): Content
                    {
                        return new Content(
                            html: '<h1>Welcome!</h1>',
                            text: 'Welcome!',
                        );
                    }
                };
            }
        };

        // Send notification
        $manager->send($notifiable, $notification);

        // Verify mail was actually sent through ArrayTransport
        $transport = $mailManager->driver();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->sent());

        $sentMessage = $transport->sent()[0];
        self::assertSame('Welcome Notification', $sentMessage->subject);
        self::assertSame('noreply@app.com', $sentMessage->from->email);
        self::assertSame('recipient@example.com', $sentMessage->to[0]->email);

        // Verify NotificationSent event was dispatched
        $sentEvents = array_filter(
            $dispatchedEvents,
            static fn(object $e): bool => $e instanceof NotificationSent,
        );
        self::assertCount(1, $sentEvents);

        $sentEvent = array_values($sentEvents)[0];
        self::assertSame('mail', $sentEvent->channel);
        self::assertSame('user-integration-1', $sentEvent->notifiableId);
    }

    #[Test]
    public function it_sends_to_multiple_channels(): void
    {
        $mailConfig = new MailConfig(
            enabled: true,
            defaultDriver: MailDriverType::Array,
            defaultFromAddress: 'noreply@app.com',
            defaultFromName: 'App',
        );

        $mailManager = new MailManager($mailConfig);
        $mailChannel = new MailChannel($mailManager);

        $logChannel = new class implements \Pulsar\Notification\NotificationChannelInterface {
            public bool $sent = false;

            public function send(NotifiableInterface $notifiable, Notification $notification): void
            {
                $this->sent = true;
            }

            public function name(): string
            {
                return 'log';
            }
        };

        $notificationConfig = new NotificationConfig(enabled: true);
        $manager = new NotificationManager(
            $notificationConfig,
            ['mail' => $mailChannel, 'log' => $logChannel],
        );

        $notifiable = new class implements NotifiableInterface {
            public function routeNotificationFor(string $channel): mixed
            {
                return 'user@test.com';
            }

            public function getNotifiableId(): string
            {
                return 'multi-channel-user';
            }

            public function preferredLocale(): ?string
            {
                return null;
            }
        };

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['mail', 'log'];
            }

            public function toMail(NotifiableInterface $notifiable): Mailable
            {
                return new class extends Mailable {
                    public function envelope(): Envelope
                    {
                        return new Envelope(subject: 'Multi');
                    }

                    public function content(): Content
                    {
                        return new Content(text: 'Hi');
                    }
                };
            }
        };

        $manager->send($notifiable, $notification);

        // Verify both channels received the notification
        $transport = $mailManager->driver();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->sent());
        self::assertTrue($logChannel->sent);
    }
}
