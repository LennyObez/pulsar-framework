<?php

declare(strict_types=1);

namespace Pulsar\Notification\Consent;

use Pulsar\Api\Api;

/**
 * Classification of notification types for consent and compliance enforcement.
 *
 * Transactional notifications bypass opt-out checks (password resets, security alerts, etc.).
 * Marketing notifications require explicit opt-in consent.
 * @api
 */
#[Api(since: '1.0.0')]
enum NotificationClassification: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';
}
