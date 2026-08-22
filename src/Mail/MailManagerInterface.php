<?php

declare(strict_types=1);

namespace Pulsar\Mail;

use Pulsar\Api\Api;
use Pulsar\Mail\Exception\MailException;

/**
 * Application mail manager: public API for sending mail.
 * @api
 */
#[Api(since: '1.0.0')]
interface MailManagerInterface
{
    /**
     * Send a mailable through the default or mailable-specified transport.
     *
     * @return string The transport-assigned message ID
     *
     * @throws MailException If the send operation fails
     */
    public function send(Mailable $mailable): string;

    /**
     * Get a specific transport driver by name.
     *
     * @param string|null $name Driver name (null = default driver)
     *
     * @throws MailException If the driver is not configured
     */
    public function driver(?string $name = null): TransportInterface;

    /**
     * Send a raw Message directly (bypasses Mailable building).
     *
     * @return string The transport-assigned message ID
     *
     * @throws MailException If the send operation fails
     */
    public function raw(Message $message): string;
}
