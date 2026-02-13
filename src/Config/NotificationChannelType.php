<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Available notification delivery channel types.
 */
#[Api(since: '1.0.0')]
enum NotificationChannelType: string
{
    case Mail = 'mail';
    case Sms = 'sms';
    case Database = 'database';
    case Slack = 'slack';
    case Webhook = 'webhook';
    case Log = 'log';
    case Broadcast = 'broadcast';
    case Push = 'push';
}
