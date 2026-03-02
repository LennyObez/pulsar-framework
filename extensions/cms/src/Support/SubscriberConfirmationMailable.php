<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Support;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriber;
use Pulsar\Mail\Address;
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Mailable;

use function htmlspecialchars;
use function urlencode;

use const ENT_QUOTES;

/**
 * Email sent to new newsletter subscribers for double opt-in confirmation.
 *
 * Contains a confirmation link with the raw token. The subscriber must
 * click the link to confirm their subscription.
 */
#[Internal(reason: 'Newsletter confirmation mailable; implementation detail')]
final class SubscriberConfirmationMailable extends Mailable
{
    public function __construct(
        private readonly NewsletterSubscriber $subscriber,
        private readonly string $confirmToken,
    ) {}

    #[Override]
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your newsletter subscription',
            to: [new Address($this->subscriber->email)],
        );
    }

    #[Override]
    public function content(): Content
    {
        $escapedEmail = htmlspecialchars($this->subscriber->email, ENT_QUOTES, 'UTF-8');
        $encodedToken = urlencode($this->confirmToken);
        $confirmUrl = "/newsletter/confirm?token=$encodedToken";
        $escapedUrl = htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="{$this->subscriber->locale}">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Confirm your subscription</title>
            </head>
            <body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 40px 20px; color: #333; line-height: 1.6;">
                <div style="text-align: center; margin-bottom: 32px;">
                    <h1 style="font-size: 24px; color: #1a1a1a; margin: 0 0 8px 0;">Confirm Your Subscription</h1>
                    <p style="font-size: 16px; color: #666; margin: 0;">You are subscribing with <strong>$escapedEmail</strong></p>
                </div>

                <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; text-align: center; margin-bottom: 24px;">
                    <p style="font-size: 16px; margin: 0 0 16px 0;">Please click the button below to confirm your newsletter subscription:</p>
                    <a href="$escapedUrl" style="display: inline-block; padding: 12px 32px; background: #2563eb; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: 600;">Confirm Subscription</a>
                </div>

                <div style="font-size: 14px; color: #9ca3af; text-align: center;">
                    <p>If you did not request this subscription, you can safely ignore this email.</p>
                    <p>If the button does not work, copy and paste this link into your browser:<br>
                    <a href="$escapedUrl" style="color: #2563eb; word-break: break-all;">$escapedUrl</a></p>
                </div>
            </body>
            </html>
            HTML;

        $text = <<<TEXT
            Confirm Your Subscription

            You are subscribing with {$this->subscriber->email}

            Please visit the following link to confirm your newsletter subscription:

            $confirmUrl

            If you did not request this subscription, you can safely ignore this email.
            TEXT;

        return new Content(
            html: $html,
            text: $text,
        );
    }
}
