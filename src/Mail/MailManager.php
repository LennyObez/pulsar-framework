<?php

declare(strict_types=1);

namespace Pulsar\Mail;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\MailConfig;
use Pulsar\Config\MailDriverType;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Mail\Event\MailFailed;
use Pulsar\Mail\Event\MailSent;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Transport\ArrayTransport;
use Pulsar\Mail\Transport\Config\MailgunTransportConfig;
use Pulsar\Mail\Transport\Config\PostmarkTransportConfig;
use Pulsar\Mail\Transport\Config\SendgridTransportConfig;
use Pulsar\Mail\Transport\Config\SesTransportConfig;
use Pulsar\Mail\Transport\Config\SmtpTransportConfig;
use Pulsar\Mail\Transport\LogTransport;
use Pulsar\Mail\Transport\MailgunTransport;
use Pulsar\Mail\Transport\MailHttpClientInterface;
use Pulsar\Mail\Transport\PostmarkTransport;
use Pulsar\Mail\Transport\SendgridTransport;
use Pulsar\Mail\Transport\SesTransport;
use Pulsar\Mail\Transport\SmtpTransport;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Throwable;

use function count;
use function is_array;
use function time;

/**
 * Application mail manager with lazy transport resolution and observability.
 */
#[Api(since: '1.0.0')]
final class MailManager implements MailManagerInterface
{
    /** @var array<string, TransportInterface> */
    private array $transports = [];

    public function __construct(
        private readonly MailConfig $config,
        private readonly ?MailHttpClientInterface $httpClient = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?AuditLoggerInterface $auditLogger = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MetricRegistry $metrics = null,
    ) {}

    public function send(Mailable $mailable): string
    {
        $defaultFrom = new Address(
            email: $this->config->defaultFromAddress,
            name: $this->config->defaultFromName,
        );

        $message = $mailable->build($defaultFrom);

        return $this->dispatchMessage($message);
    }

    public function driver(?string $name = null): TransportInterface
    {
        $driverType = $name !== null
            ? (MailDriverType::tryFrom($name) ?? throw MailException::transportNotConfigured($name))
            : $this->config->defaultDriver;

        $key = $driverType->value;

        if (!isset($this->transports[$key])) {
            $this->transports[$key] = $this->resolveTransport($driverType);
        }

        return $this->transports[$key];
    }

    public function raw(Message $message): string
    {
        return $this->dispatchMessage($message);
    }

    private function dispatchMessage(Message $message): string
    {
        $transport = $this->driver();
        $driverName = $transport->name();

        $this->metrics?->counter('mail_send_total', 'Total mail send attempts')
            ->increment(new LabelSet(['driver' => $driverName]));

        try {
            $messageId = $transport->send($message);

            $recipientCount = count($message->to) + count($message->cc) + count($message->bcc);

            $this->emitSentEvent($messageId, $recipientCount, $driverName);
            $this->logAudit($messageId, $message, $driverName, true);

            $this->logger?->info('Mail sent successfully', [
                'message_id' => $messageId,
                'driver' => $driverName,
                'recipient_count' => $recipientCount,
            ]);

            return $messageId;
        } catch (Throwable $e) {
            $this->metrics?->counter('mail_send_errors_total', 'Total mail send errors')
                ->increment(new LabelSet(['driver' => $driverName]));

            $this->emitFailedEvent($driverName, $e->getMessage());
            $this->logAudit('', $message, $driverName, false, $e->getMessage());

            $this->logger?->error('Mail send failed', [
                'driver' => $driverName,
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof MailException) {
                throw $e;
            }

            throw MailException::sendFailed($e->getMessage(), $e);
        }
    }

    private function resolveTransport(MailDriverType $driverType): TransportInterface
    {
        /** @var array<string, mixed> $options */
        $options = is_array($this->config->driverOptions[$driverType->value] ?? null)
            ? $this->config->driverOptions[$driverType->value]
            : [];

        return match ($driverType) {
            MailDriverType::Smtp => new SmtpTransport(SmtpTransportConfig::fromArray($options)),
            MailDriverType::Ses => new SesTransport(
                SesTransportConfig::fromArray($options),
                $this->requireHttpClient('ses'),
            ),
            MailDriverType::Mailgun => new MailgunTransport(
                MailgunTransportConfig::fromArray($options),
                $this->requireHttpClient('mailgun'),
            ),
            MailDriverType::Postmark => new PostmarkTransport(
                PostmarkTransportConfig::fromArray($options),
                $this->requireHttpClient('postmark'),
            ),
            MailDriverType::Sendgrid => new SendgridTransport(
                SendgridTransportConfig::fromArray($options),
                $this->requireHttpClient('sendgrid'),
            ),
            MailDriverType::Log => new LogTransport(
                $this->logger ?? throw MailException::driverError('log', 'LoggerInterface is required for log transport'),
            ),
            MailDriverType::Array => new ArrayTransport(),
        };
    }

    private function requireHttpClient(string $driverName): MailHttpClientInterface
    {
        if ($this->httpClient === null) {
            throw MailException::driverError($driverName, 'MailHttpClientInterface is required for API-based transports');
        }

        return $this->httpClient;
    }

    private function emitSentEvent(string $messageId, int $recipientCount, string $driver): void
    {
        $this->eventDispatcher?->dispatch(new MailSent(
            messageId: $messageId,
            occurredAt: time(),
            recipientCount: $recipientCount,
            driver: $driver,
        ));
    }

    private function emitFailedEvent(string $driver, string $reason): void
    {
        $this->eventDispatcher?->dispatch(new MailFailed(
            messageId: '',
            occurredAt: time(),
            reason: $reason,
            driver: $driver,
        ));
    }

    private function logAudit(
        string $messageId,
        Message $message,
        string $driver,
        bool $success,
        string $errorReason = '',
    ): void {
        if ($this->auditLogger === null) {
            return;
        }

        $recipientCount = count($message->to) + count($message->cc) + count($message->bcc);

        $this->auditLogger->log(
            event: AuditEvent::Communication,
            outcome: $success ? AuditOutcome::Success : AuditOutcome::Error,
            actor: AuditActor::system('mail.manager'),
            action: 'mail.send',
            resource: $messageId,
            metadata: [
                'message_id' => $messageId,
                'driver' => $driver,
                'recipient_count' => $recipientCount,
                'has_attachments' => $message->attachments !== [],
                'error' => $errorReason !== '' ? $errorReason : null,
            ],
        );
    }
}
