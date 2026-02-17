<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Mail\Address;
use Pulsar\Mail\Message;
use Pulsar\Mail\TransportInterface;

use function array_map;
use function bin2hex;
use function count;
use function random_bytes;
use function sprintf;

/**
 * Log-based mail transport for development and testing.
 *
 * Writes message details to a PSR-3 logger instead of sending email.
 */
#[Internal]
final class LogTransport implements TransportInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function send(Message $message): string
    {
        $messageId = sprintf('<log-%s@localhost>', bin2hex(random_bytes(16)));

        $this->logger->info('Mail message sent via log transport', [
            'message_id' => $messageId,
            'from' => $this->formatAddress($message->from),
            'to' => array_map($this->formatAddress(...), $message->to),
            'cc' => array_map($this->formatAddress(...), $message->cc),
            'bcc' => array_map($this->formatAddress(...), $message->bcc),
            'reply_to' => $message->replyTo !== null ? $this->formatAddress($message->replyTo) : null,
            'subject' => $message->subject,
            'has_html' => $message->htmlBody !== null,
            'has_text' => $message->textBody !== null,
            'attachment_count' => count($message->attachments),
            'priority' => $message->priority,
            'headers' => $message->headers,
            'metadata' => $message->metadata,
        ]);

        return $messageId;
    }

    public function name(): string
    {
        return 'log';
    }

    private function formatAddress(Address $address): string
    {
        if ($address->name !== '') {
            return sprintf('%s <%s>', $address->name, $address->email);
        }

        return $address->email;
    }
}
