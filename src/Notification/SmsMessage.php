<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;

/**
 * DTO representing an SMS notification message.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SmsMessage
{
    /**
     * @param string      $to   Recipient phone number (E.164 format)
     * @param string      $body Message body
     * @param string|null $from Sender phone number or sender ID
     */
    public function __construct(
        public string $to,
        public string $body,
        public ?string $from = null,
    ) {}
}
