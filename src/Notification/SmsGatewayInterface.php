<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;

/**
 * Port interface for SMS delivery gateways.
 *
 * Implementations wrap provider-specific APIs (Twilio, Vonage, etc.).
 * @api
 */
#[Api(since: '1.0.0')]
interface SmsGatewayInterface
{
    /**
     * Send an SMS message through the gateway.
     */
    public function send(SmsMessage $message): void;
}
