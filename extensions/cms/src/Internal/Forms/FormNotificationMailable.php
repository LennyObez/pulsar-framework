<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Forms;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Forms\FormSubmission;
use Pulsar\Mail\Address;
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Mailable;

use function array_map;
use function htmlspecialchars;
use function implode;
use function is_scalar;
use function is_string;

use const ENT_QUOTES;

/**
 * Email notification sent when a new form submission is received.
 */
#[Internal(reason: 'Form notification mailable; implementation detail')]
final class FormNotificationMailable extends Mailable
{
    /**
     * @param list<string> $recipients
     */
    public function __construct(
        private readonly FormSubmission $submission,
        private readonly array $recipients,
    ) {}

    #[Override]
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New form submission',
            to: array_map(
                static fn(string $email): Address => new Address($email),
                $this->recipients,
            ),
        );
    }

    #[Override]
    public function content(): Content
    {
        $rows = [];

        foreach ($this->submission->data as $key => $value) {
            // Skip internal fields
            if (str_starts_with($key, '_')) {
                continue;
            }

            $escapedKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $escapedValue = htmlspecialchars(is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''), ENT_QUOTES, 'UTF-8');

            $rows[] = "<tr><td style=\"padding:8px;border:1px solid #ddd;font-weight:bold;vertical-align:top\">$escapedKey</td>"
                . "<td style=\"padding:8px;border:1px solid #ddd\">$escapedValue</td></tr>";
        }

        $tableRows = implode("\n", $rows);
        $submittedAt = htmlspecialchars($this->submission->submittedAt->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head><meta charset="utf-8"><title>New Form Submission</title></head>
            <body style="font-family:sans-serif;color:#333;max-width:600px;margin:0 auto;padding:20px">
                <h2 style="color:#1a1a1a;border-bottom:2px solid #eee;padding-bottom:10px">New Form Submission</h2>
                <p>A new form submission was received on <strong>$submittedAt</strong>.</p>
                <table style="width:100%;border-collapse:collapse;margin:20px 0">
                    <thead>
                        <tr>
                            <th style="padding:8px;border:1px solid #ddd;background:#f5f5f5;text-align:left">Field</th>
                            <th style="padding:8px;border:1px solid #ddd;background:#f5f5f5;text-align:left">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        $tableRows
                    </tbody>
                </table>
                <p style="color:#666;font-size:12px">Submission ID: {$this->submission->id}</p>
            </body>
            </html>
            HTML;

        // Plain text version
        $textLines = ['New Form Submission', '', 'Submitted at: ' . $this->submission->submittedAt->format('Y-m-d H:i:s T'), ''];

        foreach ($this->submission->data as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            $textLines[] = $key . ': ' . (is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''));
        }

        $textLines[] = '';
        $textLines[] = 'Submission ID: ' . $this->submission->id;

        return new Content(
            html: $html,
            text: implode("\n", $textLines),
        );
    }
}
