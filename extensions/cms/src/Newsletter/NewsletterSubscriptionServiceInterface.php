<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Service interface for newsletter subscription operations.
 *
 * Handles the full subscriber lifecycle: subscribe (with double opt-in),
 * confirm via token, unsubscribe, and re-subscribe.
 *
 * @psalm-api Public binding contract; implemented by NewsletterSubscriptionService
 *            and consumed by public subscription form controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface NewsletterSubscriptionServiceInterface
{
    /**
     * Subscribe an email address to the newsletter.
     *
     * Creates a pending subscriber and sends a confirmation email
     * with a token for double opt-in verification.
     *
     * @throws CmsException If the email is already confirmed for this tenant
     */
    public function subscribe(
        string $email,
        string $locale,
        string $source,
        string $ipAddress,
        ?string $tenantId = null,
    ): NewsletterSubscriber;

    /**
     * Confirm a subscription via the token from the confirmation email.
     *
     * Hashes the provided token and matches against stored confirm_token_hash.
     *
     * @throws CmsException If no matching pending subscriber is found
     */
    public function confirm(string $token): bool;

    /**
     * Unsubscribe a subscriber by their ID.
     *
     * @throws CmsException If the subscriber is not found
     */
    public function unsubscribe(string $subscriberId): bool;

    /**
     * Re-subscribe a previously unsubscribed email address.
     *
     * Resets the subscriber to pending status and sends a new confirmation email.
     *
     * @throws CmsException If the email is not found or is not unsubscribed
     */
    public function resubscribe(string $email, ?string $tenantId = null): NewsletterSubscriber;
}
