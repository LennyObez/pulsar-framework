<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Newsletter;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Newsletter\NewsletterSendRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\SendStatus;
use Pulsar\Http\Message\Response;

use function base64_decode;
use function bin2hex;
use function hash_equals;
use function is_string;
use function sodium_crypto_generichash;

/**
 * Public controller for newsletter tracking endpoints.
 *
 * Handles open tracking via a 1x1 transparent GIF pixel and click tracking
 * via redirect links. Both endpoints are guard-checked against a tracking
 * configuration flag.
 */
#[Internal(reason: 'CMS newsletter controller — implementation detail')]
final readonly class TrackingController
{
    /** 1x1 transparent GIF — 43 bytes */
    private const string TRACKING_PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private NewsletterSendRepositoryInterface $sendRepository,
        private string $hmacKey,
        private bool $trackingEnabled = true,
    ) {}

    /**
     * GET /newsletter/track/open?s={sendId}&sig={signature}
     *
     * Returns a 1x1 transparent GIF and records the open event.
     */
    public function pixel(ServerRequestInterface $request): Response
    {
        if ($this->trackingEnabled) {
            $params = $request->getQueryParams();
            $sendId = is_string($params['s'] ?? null) ? $params['s'] : '';
            $signature = is_string($params['sig'] ?? null) ? $params['sig'] : '';

            if ($sendId !== '' && $this->validateSignature($sendId, 'open', $signature)) {
                $this->sendRepository->updateStatus($sendId, SendStatus::Delivered);
            }
        }

        $pixelData = base64_decode(self::TRACKING_PIXEL, true);

        return new Response(
            headers: [
                'Content-Type' => 'image/gif',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ],
            body: $pixelData !== false ? $pixelData : '',
        );
    }

    /**
     * GET /newsletter/track/click?s={sendId}&url={encodedUrl}&sig={signature}
     *
     * Redirects to the original URL and records the click event.
     */
    public function click(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var string $sendId */
        $sendId = is_string($params['s'] ?? null) ? $params['s'] : '';
        /** @var string $url */
        $url = is_string($params['url'] ?? null) ? $params['url'] : '';
        /** @var string $signature */
        $signature = is_string($params['sig'] ?? null) ? $params['sig'] : '';

        if ($url === '') {
            return Response::json(['error' => 'Missing URL parameter'], 400);
        }

        if ($this->trackingEnabled && $sendId !== '' && $this->validateSignature($sendId, 'click:' . $url, $signature)) {
            $this->sendRepository->updateStatus($sendId, SendStatus::Delivered);
        }

        return new Response(
            statusCode: 302,
            headers: [
                'Location' => $url,
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function validateSignature(string $sendId, string $action, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $payload = $sendId . ':' . $action;
        $expected = bin2hex(sodium_crypto_generichash($payload, $this->hmacKey));

        return hash_equals($expected, $signature);
    }
}
