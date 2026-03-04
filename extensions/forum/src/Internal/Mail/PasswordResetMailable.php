<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Mail;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Mail\Address;
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Mailable;

use function htmlspecialchars;
use function urlencode;

/**
 * Mailable for password reset emails.
 *
 * Sends a one-time password reset link to the user. The token is included
 * in the URL and the link is valid for one hour.
 */
#[Internal(reason: 'Forum mail; implementation detail')]
final class PasswordResetMailable extends Mailable
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly string $token,
        private readonly string $baseUrl = '',
    ) {}

    #[Override]
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset Your Password',
            to: [new Address($this->recipientEmail)],
        );
    }

    #[Override]
    public function content(): Content
    {
        $resetUrl = $this->baseUrl . '/reset-password?token=' . urlencode($this->token);
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<HTML
            <h1>Password Reset</h1>
            <p>You requested a password reset. Click the link below to set a new password:</p>
            <p><a href="{$safeUrl}">Reset Password</a></p>
            <p>This link will expire in 1 hour. If you did not request this, you can safely ignore this email.</p>
            HTML;

        $text = "Password Reset\n\n"
            . "You requested a password reset. Visit the following link to set a new password:\n\n"
            . $resetUrl . "\n\n"
            . "This link will expire in 1 hour. If you did not request this, you can safely ignore this email.\n";

        return new Content(html: $html, text: $text);
    }
}
