<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use Pulsar\Api\Api;

/**
 * Status lifecycle for newsletter subscribers.
 *
 * Pending → Confirmed (via email confirmation link)
 * Confirmed → Unsubscribed (via unsubscribe link or admin action)
 * Unsubscribed → Confirmed (via re-subscribe)
 *
 * @psalm-api Public enum referenced by NewsletterSubscriber::status; consumed
 *            by subscription service and admin views.
 */
#[Api(since: '1.0.0')]
enum SubscriberStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Unsubscribed = 'unsubscribed';
}
