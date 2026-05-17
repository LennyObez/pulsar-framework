<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Reminder;

use Pulsar\Api\Api;
use Pulsar\Extension\Booking\Exception\BookingException;

/**
 * Contract for SMS delivery providers.
 * @api
 */
#[Api(since: '1.0.0')]
interface SmsProviderInterface
{
    /**
     * Send an SMS message.
     *
     * @param string $to Recipient phone number (E.164 format)
     * @param string $body Message body
     *
     * @throws BookingException If delivery fails
     */
    public function send(string $to, string $body): void;
}
