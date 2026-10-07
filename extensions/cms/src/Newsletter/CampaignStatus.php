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
 *
 * @psalm-api Public enum referenced by NewsletterCampaign::status; consumed
 *            by user-land code and admin views.
 * @api
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
