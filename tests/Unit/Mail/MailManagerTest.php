<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail;

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
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\MailManager;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\ArrayTransport;
use Pulsar\Mail\Transport\MailgunTransport;
use Pulsar\Mail\Transport\MailHttpClientInterface;
use RuntimeException;

#[CoversClass(MailManager::class)]
final class MailManagerTest extends TestCase
{
    private MailConfig $config;

    protected function setUp(): void
    {
        $this->config = new MailConfig(
            enabled: true,
            defaultDriver: MailDriverType::Array,
            defaultFromAddress: 'noreply@test.com',
            defaultFromName: 'Test App',
        );
    }

    #[Test]
    public function it_resolves_default_driver(): void
    {
        $manager = new MailManager($this->config);
        $transport = $manager->driver();

        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertSame('array', $transport->name());
    }

    #[Test]
    public function it_caches_resolved_drivers(): void
    {
        $manager = new MailManager($this->config);

        $first = $manager->driver();
        $second = $manager->driver();

        self::assertSame($first, $second);
    }

    #[Test]
    public function it_resolves_driver_by_name(): void
    {
        $manager = new MailManager($this->config);
        $transport = $manager->driver('array');

        self::assertInstanceOf(ArrayTransport::class, $transport);
    }

    #[Test]
    public function it_throws_for_unknown_driver_name(): void
    {
        $manager = new MailManager($this->config);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('not configured');

        $manager->driver('nonexistent');
    }

    #[Test]
    public function it_resolves_api_transport_when_http_client_is_provided(): void
    {
        // With an HTTP client supplied (as MailWiring now does by default), an
        // API-based driver resolves instead of throwing "required".
        $config = new MailConfig(
            enabled: true,
            defaultDriver: MailDriverType::Mailgun,
            driverOptions: ['mailgun' => ['domain' => 'example.com', 'api_key' => 'key-abc']],
        );
        $manager = new MailManager($config, $this->createStub(MailHttpClientInterface::class));

        self::assertInstanceOf(MailgunTransport::class, $manager->driver('mailgun'));
    }

    #[Test]
    public function it_throws_for_api_transport_without_http_client(): void
    {
        // The original bug: no client -> API transports are unusable.
        $config = new MailConfig(
            enabled: true,
            defaultDriver: MailDriverType::Mailgun,
            driverOptions: ['mailgun' => ['domain' => 'example.com', 'api_key' => 'key-abc']],
        );
        $manager = new MailManager($config);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('MailHttpClientInterface is required');

        $manager->driver('mailgun');
    }

    #[Test]
    public function it_sends_mailable_and_returns_message_id(): void
    {
        $manager = new MailManager($this->config);

        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(
                    subject: 'Test Subject',
                    to: [new Address('user@test.com')],
                );
            }

            public function content(): Content
            {
                return new Content(html: '<p>Hello</p>');
            }
        };

        $messageId = $manager->send($mailable);

        self::assertNotEmpty($messageId);
        self::assertStringStartsWith('<array-', $messageId);
    }

    #[Test]
    public function it_dispatches_mail_sent_event(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(MailSent::class));

        $manager = new MailManager(
            $this->config,
            eventDispatcher: $dispatcher,
        );

        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(
                    subject: 'Test',
                    to: [new Address('user@test.com')],
                );
            }

            public function content(): Content
            {
                return new Content(text: 'Hello');
            }
        };

        $manager->send($mailable);
    }

    #[Test]
    public function it_throws_when_log_driver_lacks_logger(): void
    {
        $config = new MailConfig(
            enabled: true,
            defaultDriver: MailDriverType::Log,
        );

        $manager = new MailManager($config);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('LoggerInterface is required');

        $manager->driver();
    }

    #[Test]
    public function it_sends_raw_message(): void
    {
        $manager = new MailManager($this->config);

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Raw Message',
            htmlBody: '<p>Raw content</p>',
        );

        $messageId = $manager->raw($message);

        self::assertNotEmpty($messageId);

        $transport = $manager->driver();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->sent());
        self::assertSame('Raw Message', $transport->sent()[0]->subject);
    }

    #[Test]
    public function it_logs_audit_on_successful_send(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log');

        $manager = new MailManager(
            $this->config,
            auditLogger: $auditLogger,
        );

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Audit Test',
        );

        $manager->raw($message);
    }

    /**
     * Regression: when the transport succeeds, a throwing success-path observer
     * (event dispatcher, audit logger, logger) must NOT be caught by the
     * failure handler and turned into a MailException. The delivered message id
     * must be returned and the failure side-effects must never fire.
     */
    #[Test]
    public function it_does_not_report_failure_when_success_event_dispatcher_throws(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        // Throw on the MailSent dispatch (the only dispatch on the success path).
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(MailSent::class))
            ->willThrowException(new RuntimeException('observer blew up'));

        $manager = new MailManager(
            $this->config,
            eventDispatcher: $dispatcher,
        );

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Observer Throws',
            textBody: 'Hello',
        );

        // The send succeeded at the transport, so the observer's failure must
        // propagate as itself — NOT be swallowed and re-thrown as a send failure.
        try {
            $manager->raw($message);
            self::fail('Expected the success-path observer exception to surface');
        } catch (MailException $e) {
            self::fail('A successful send was falsely reported as a MailException: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            self::assertSame('observer blew up', $e->getMessage());
        }

        // The message must have been handed to the transport exactly once: the
        // failure path (which would re-send nothing but emit MailFailed) never ran.
        $transport = $manager->driver();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->sent());
    }
}
