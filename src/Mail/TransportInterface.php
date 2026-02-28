<?php

declare(strict_types=1);

namespace Pulsar\Mail;

use Pulsar\Api\Api;
use Pulsar\Mail\Exception\MailException;

/**
 * Low-level mail transport: sends a raw Message and returns a message ID.
 */
#[Api(since: '1.0.0')]
interface TransportInterface
{
    /**
     * Send an email message through this transport.
     *
     * @return string The transport-assigned message ID
     *
     * @throws MailException If the send operation fails
     */
    public function send(Message $message): string;

    /**
     * Get the transport name (e.g. "smtp", "ses", "log").
     */
    public function name(): string;
}
