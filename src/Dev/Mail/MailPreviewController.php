<?php

declare(strict_types=1);

namespace Pulsar\Dev\Mail;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

use function array_map;
use function count;
use function date;
use function htmlspecialchars;
use function implode;
use function sprintf;

use const ENT_QUOTES;

/**
 * Serves a UI to browse and preview captured dev emails.
 *
 * Provides three views:
 * - Index (list of all captured messages)
 * - HTML preview of a specific message
 * - Text preview of a specific message
 *
 * Integrates with Studio by rendering content compatible with its layout.
 */
#[Internal]
final readonly class MailPreviewController
{
    public function __construct(
        private MailCaptureDriver $driver,
    ) {}

    /**
     * List all captured emails.
     */
    public function index(ServerRequestInterface $_request): Response
    {
        $messages = $this->driver->all();

        $rows = '';
        foreach ($messages as $captured) {
            $id = $this->esc($captured->id);
            $from = $this->esc($captured->message->from->email);
            $to = $this->esc(implode(', ', array_map(
                static fn(\Pulsar\Mail\Address $addr): string => $addr->email,
                $captured->message->to,
            )));
            $subject = $this->esc($captured->message->subject);
            $time = date('Y-m-d H:i:s', (int) $captured->capturedAt);
            $attachments = count($captured->message->attachments);

            $rows .= sprintf(
                '<tr>'
                . '<td><a href="?action=preview&id=%s">%s</a></td>'
                . '<td>%s</td>'
                . '<td>%s</td>'
                . '<td>%s</td>'
                . '<td>%d</td>'
                . '<td>%s</td>'
                . '</tr>',
                $id,
                $subject !== '' ? $subject : '(no subject)',
                $from,
                $to,
                $captured->message->htmlBody !== null ? 'HTML' : 'Text',
                $attachments,
                $time,
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6" style="text-align:center;color:#64748b;padding:2rem;">No emails captured yet. Send an email in dev mode to see it here.</td></tr>';
        }

        $count = $this->driver->count();
        $html = $this->layout("Mail Preview ({$count})", <<<BODY
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <h2 style="font-size:1.25rem;color:#e2e8f0;margin:0;">Captured Emails ({$count})</h2>
                <form method="post" action="?action=flush" style="margin:0;">
                    <button type="submit" style="background:#b91c1c;color:#fff;border:none;padding:0.5rem 1rem;border-radius:6px;cursor:pointer;font-size:0.8125rem;">Clear All</button>
                </form>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid #334155;">
                        <th style="text-align:left;padding:0.5rem;color:#64748b;">Subject</th>
                        <th style="text-align:left;padding:0.5rem;color:#64748b;">From</th>
                        <th style="text-align:left;padding:0.5rem;color:#64748b;">To</th>
                        <th style="text-align:left;padding:0.5rem;color:#64748b;">Type</th>
                        <th style="text-align:left;padding:0.5rem;color:#64748b;">Attachments</th>
                        <th style="text-align:left;padding:0.5rem;color:#64748b;">Time</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
            BODY);

        return Response::html($html);
    }

    /**
     * Preview a specific captured email (HTML or text).
     */
    public function preview(ServerRequestInterface $request): Response
    {
        /** @var array<string, string> $query */
        $query = $request->getQueryParams();
        $id = $query['id'] ?? '';

        $captured = $this->driver->find($id);
        if ($captured === null) {
            return new Response(
                statusCode: ResponseStatus::NotFound->value,
                body: 'Message not found',
            );
        }

        $message = $captured->message;
        $subject = $this->esc($message->subject);
        $from = $this->esc($message->from->email);
        $to = $this->esc(implode(', ', array_map(
            static fn(\Pulsar\Mail\Address $addr): string => $addr->email,
            $message->to,
        )));

        $mode = $query['mode'] ?? 'html';
        $previewContent = match ($mode) {
            'text' => '<pre style="white-space:pre-wrap;background:#0f172a;padding:1rem;border-radius:6px;color:#cbd5e1;">'
                . $this->esc($message->textBody ?? '(no text body)')
                . '</pre>',
            'headers' => $this->renderHeaders($message),
            default => $message->htmlBody !== null
                ? '<iframe srcdoc="' . $this->esc($message->htmlBody) . '" style="width:100%;height:500px;border:1px solid #334155;border-radius:6px;background:#fff;"></iframe>'
                : '<p style="color:#64748b;font-style:italic;">No HTML body.</p>',
        };

        $attachmentList = '';
        foreach ($message->attachments as $attachment) {
            $attachmentList .= sprintf(
                '<li style="padding:0.25rem 0;color:#94a3b8;">%s (%s)</li>',
                $this->esc($attachment->filename),
                $this->esc($attachment->mimeType),
            );
        }

        $attachmentSection = $attachmentList !== ''
            ? '<h3 style="margin-top:1rem;color:#64748b;font-size:0.875rem;">Attachments</h3><ul style="list-style:none;padding:0;">' . $attachmentList . '</ul>'
            : '';

        $html = $this->layout('Preview: ' . $subject, <<<BODY
            <p style="margin-bottom:1rem;"><a href="?action=index" style="color:#7399e6;text-decoration:none;">&larr; Back to list</a></p>
            <div style="background:#1e293b;padding:1.5rem;border-radius:8px;margin-bottom:1rem;">
                <table style="font-size:0.8125rem;width:100%;">
                    <tr><td style="color:#64748b;padding:0.25rem 1rem 0.25rem 0;width:60px;">From</td><td style="color:#e2e8f0;">{$from}</td></tr>
                    <tr><td style="color:#64748b;padding:0.25rem 1rem 0.25rem 0;">To</td><td style="color:#e2e8f0;">{$to}</td></tr>
                    <tr><td style="color:#64748b;padding:0.25rem 1rem 0.25rem 0;">Subject</td><td style="color:#e2e8f0;font-weight:600;">{$subject}</td></tr>
                </table>
            </div>
            <div style="margin-bottom:1rem;">
                <a href="?action=preview&id={$this->esc($id)}&mode=html" style="color:#7399e6;margin-right:1rem;text-decoration:none;">HTML</a>
                <a href="?action=preview&id={$this->esc($id)}&mode=text" style="color:#7399e6;margin-right:1rem;text-decoration:none;">Text</a>
                <a href="?action=preview&id={$this->esc($id)}&mode=headers" style="color:#7399e6;text-decoration:none;">Headers</a>
            </div>
            {$previewContent}
            {$attachmentSection}
            BODY);

        return Response::html($html);
    }

    /**
     * Flush all captured messages.
     */
    public function flush(ServerRequestInterface $_request): Response
    {
        $this->driver->flush();

        return new Response(
            statusCode: ResponseStatus::Found->value,
            headers: ['Location' => '?action=index'],
        );
    }

    /**
     * Route a request to the appropriate action.
     */
    public function handle(ServerRequestInterface $request): Response
    {
        /** @var array<string, string> $query */
        $query = $request->getQueryParams();
        $action = $query['action'] ?? 'index';

        if ($request->getMethod() === 'POST' && $action === 'flush') {
            return $this->flush($request);
        }

        return match ($action) {
            'preview' => $this->preview($request),
            default => $this->index($request),
        };
    }

    private function renderHeaders(\Pulsar\Mail\Message $message): string
    {
        $html = '<table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">';

        foreach ($message->headers as $name => $value) {
            $html .= sprintf(
                '<tr><td style="color:#0039cb;padding:0.375rem;border-bottom:1px solid #334155;width:200px;font-weight:600;">%s</td><td style="padding:0.375rem;border-bottom:1px solid #334155;color:#cbd5e1;">%s</td></tr>',
                $this->esc($name),
                $this->esc($value),
            );
        }

        if ($message->headers === []) {
            $html .= '<tr><td colspan="2" style="color:#64748b;padding:1rem;text-align:center;">No custom headers.</td></tr>';
        }

        $html .= '</table>';

        return $html;
    }

    private function layout(string $title, string $body): string
    {
        $escapedTitle = $this->esc($title);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$escapedTitle} &mdash; Pulsar Studio</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { font-family: 'Overpass', system-ui, sans-serif; background: #0f172a; color: #e2e8f0; }
                    .mail-preview { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem; }
                    h1 { font-family: 'Montserrat', system-ui, sans-serif; font-size: 1.5rem; color: #e2e8f0; margin-bottom: 1.5rem; }
                    a { color: #7399e6; }
                    a:hover { color: #c5d4f5; }
                    table a { text-decoration: none; }
                    tbody tr { border-bottom: 1px solid #334155; }
                    tbody tr:hover { background: #1e293b; }
                    tbody td { padding: 0.5rem; color: #cbd5e1; }
                </style>
            </head>
            <body>
                <div class="mail-preview">
                    <h1>{$escapedTitle}</h1>
                    {$body}
                </div>
            </body>
            </html>
            HTML;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
