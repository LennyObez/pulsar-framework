<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use Pulsar\Api\Api;

/**
 * Status for individual email sends within a campaign.
 *
 * Queued → Sent → Delivered
 * Queued → Failed
 * Sent → Bounced
 *
 * @psalm-api Public enum referenced by NewsletterSend::status; consumed by
 *            dispatch jobs and admin send-detail views.
 * @api
 */
#[Api(since: '1.0.0')]
enum SendStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Failed = 'failed';
}
