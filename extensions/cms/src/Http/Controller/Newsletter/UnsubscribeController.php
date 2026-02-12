<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Newsletter;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriptionServiceInterface;
use Pulsar\Http\Message\Response;

use function bin2hex;
use function hash_equals;
use function is_string;
use function sodium_crypto_generichash;
use function time;

/**
 * Public controller for newsletter unsubscribe links.
 *
 * Validates HMAC signatures on unsubscribe URLs to prevent unauthorized
 * unsubscription. Links expire after 90 days.
 */
#[Internal(reason: 'CMS newsletter controller — implementation detail')]
final readonly class UnsubscribeController
{
    private const int SIGNATURE_TTL_SECONDS = 7_776_000; // 90 days

    public function __construct(
        private NewsletterSubscriptionServiceInterface $subscriptionService,
        private string $hmacKey,
    ) {}

    /**
     * GET /newsletter/unsubscribe?id={subscriberId}&sig={signature}&t={timestamp}
     *
     * Validates the HMAC signature, checks expiry, and unsubscribes.
     */
    public function unsubscribe(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        /** @var string $subscriberId */
        $subscriberId = is_string($params['id'] ?? null) ? $params['id'] : '';
        /** @var string $signature */
        $signature = is_string($params['sig'] ?? null) ? $params['sig'] : '';
        /** @var int|string $rawTimestamp */
        $rawTimestamp = $params['t'] ?? 0;
        $timestamp = (int) $rawTimestamp;

        if ($subscriberId === '' || $signature === '' || $timestamp === 0) {
            return $this->renderPage(
                'Invalid Unsubscribe Link',
                'The unsubscribe link is invalid or incomplete. Please use the link from your email.',
                400,
            );
        }

        // Check expiry
        if (time() - $timestamp > self::SIGNATURE_TTL_SECONDS) {
            return $this->renderPage(
                'Link Expired',
                'This unsubscribe link has expired. Please contact support for assistance.',
                410,
            );
        }

        // Validate HMAC
        $expectedPayload = $subscriberId . ':' . $timestamp;
        $expectedSignature = bin2hex(sodium_crypto_generichash($expectedPayload, $this->hmacKey));

        if (!hash_equals($expectedSignature, $signature)) {
            return $this->renderPage(
                'Invalid Signature',
                'The unsubscribe link signature is invalid.',
                403,
            );
        }

        try {
            $this->subscriptionService->unsubscribe($subscriberId);

            return $this->renderPage(
                'Unsubscribed Successfully',
                'You have been unsubscribed from our newsletter. You will no longer receive emails from us.',
                200,
            );
        } catch (CmsException $e) {
            return $this->renderPage(
                'Unsubscribe Failed',
                'We were unable to process your unsubscribe request: ' . $e->getMessage(),
                422,
            );
        }
    }

    private function renderPage(string $title, string $message, int $status): Response
    {
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$title}</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 600px; margin: 80px auto; padding: 20px; color: #333; text-align: center; }
                    h1 { color: #1a1a1a; font-size: 24px; margin-bottom: 16px; }
                    p { font-size: 16px; line-height: 1.6; color: #666; }
                </style>
            </head>
            <body>
                <h1>{$title}</h1>
                <p>{$message}</p>
            </body>
            </html>
            HTML;

        return Response::html($html, $status);
    }
}
