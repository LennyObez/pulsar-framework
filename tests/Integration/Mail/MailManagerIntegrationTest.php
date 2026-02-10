<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Mail;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\MailConfig;
use Pulsar\Config\MailDriverType;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Mail\Address;
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Event\MailSent;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\MailManager;
use Pulsar\Mail\Transport\ArrayTransport;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(MailManager::class)]
final class MailManagerIntegrationTest extends TestCase
{
    #[Test]
    public function it_sends_mailable_end_to_end_with_array_transport(): void
    {
        $config = new MailConfig(
            enabled: true,
            defaultDriver: MailDriverType::Array,
            defaultFromAddress: 'noreply@app.com',
            defaultFromName: 'App',
        );

        $dispatchedEvents = [];
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;
                return $event;
            });

        $auditLogged = false;
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')
            ->willReturnCallback(function () use (&$auditLogged): AuditEntry {
                $auditLogged = true;
                return new AuditEntry(
                    id: 'audit-1',
                    event: AuditEvent::Communication,
                    outcome: AuditOutcome::Success,
                    actor: '',
                    action: 'mail.send',
                    resource: '',
                    timestamp: new DateTimeImmutable(),
                    metadata: [],
                    previousHmac: '',
                    hmac: '',
                );
            });

        $manager = new MailManager(
            $config,
            eventDispatcher: $dispatcher,
            auditLogger: $auditLogger,
        );

        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(
                    subject: 'Welcome to Pulsar',
                    to: [new Address('user@example.com', 'User')],
                );
            }

            public function content(): Content
            {
                return new Content(
                    html: '<h1>Welcome</h1><p>Thanks for signing up.</p>',
                    text: 'Welcome! Thanks for signing up.',
                );
            }
        };

        $messageId = $manager->send($mailable);

        // Verify message was stored in ArrayTransport
        $transport = $manager->driver();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->sent());

        $sent = $transport->sent()[0];
        self::assertSame('Welcome to Pulsar', $sent->subject);
        self::assertSame('noreply@app.com', $sent->from->email);
        self::assertSame('App', $sent->from->name);
        self::assertSame('user@example.com', $sent->to[0]->email);
        self::assertNotNull($sent->htmlBody);
        self::assertNotNull($sent->textBody);

        // Verify message ID was returned
        self::assertNotEmpty($messageId);

        // Verify MailSent event was dispatched
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(MailSent::class, $dispatchedEvents[0]);
        self::assertSame($messageId, $dispatchedEvents[0]->messageId);
        self::assertSame(1, $dispatchedEvents[0]->recipientCount);
        self::assertSame('array', $dispatchedEvents[0]->driver);

        // Verify audit was logged
        self::assertTrue($auditLogged);
    }

    #[Test]
    public function it_sends_raw_message_end_to_end(): void
    {
        $config = new MailConfig(
            enabled: true,
            defaultDriver: MailDriverType::Array,
            defaultFromAddress: 'noreply@app.com',
            defaultFromName: 'App',
        );

        $manager = new MailManager($config);

        $message = new \Pulsar\Mail\Message(
            from: new Address('custom@app.com', 'Custom'),
            to: [
                new Address('alice@test.com'),
                new Address('bob@test.com'),
            ],
            subject: 'Multi-recipient',
            htmlBody: '<p>Hello everyone</p>',
        );

        $messageId = $manager->raw($message);

        $transport = $manager->driver();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->sent());
        self::assertSame('Multi-recipient', $transport->sent()[0]->subject);
        self::assertCount(2, $transport->sent()[0]->to);
        self::assertNotEmpty($messageId);
    }

    #[Test]
    public function it_resolves_same_driver_across_send_and_driver_calls(): void
    {
        $config = new MailConfig(
            enabled: true,
            defaultDriver: MailDriverType::Array,
        );

        $manager = new MailManager($config);

        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(
                    subject: 'Test',
                    to: [new Address('test@test.com')],
                );
            }

            public function content(): Content
            {
                return new Content(text: 'Test body');
            }
        };

        $manager->send($mailable);

        // The driver used during send should be the same cached instance
        $transport = $manager->driver();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->sent());
    }
}
