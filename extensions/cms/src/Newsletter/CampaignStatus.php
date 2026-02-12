<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use Pulsar\Api\Api;

/**
 * Status lifecycle for newsletter campaigns.
 *
 * Draft → Scheduled | Sending
 * Scheduled → Sending | Cancelled
 * Sending → Sent
 * Cancelled (terminal)
 * Sent (terminal)
 */
#[Api(since: '1.0.0')]
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
}
