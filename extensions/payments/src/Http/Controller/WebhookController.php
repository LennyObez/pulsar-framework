<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Internal\Webhook\BancontactWebhookHandler;
use Pulsar\Extension\Payments\Internal\Webhook\MobileWebhookHandler;
use Pulsar\Extension\Payments\Internal\Webhook\PayconiqWebhookHandler;
use Pulsar\Extension\Payments\Internal\Webhook\PayPalWebhookHandler;
use Pulsar\Extension\Payments\Internal\Webhook\StripeWebhookHandler;
use Pulsar\Http\Message\Response;
use Throwable;

/**
 * Unified webhook controller for all payment providers.
 *
 * Routes incoming webhooks to the appropriate provider-specific handler.
 */
#[Internal]
final readonly class WebhookController
{
    public function __construct(
        private StripeWebhookHandler $stripeHandler,
        private PayPalWebhookHandler $paypalHandler,
        private MobileWebhookHandler $mobileHandler,
        private PayconiqWebhookHandler $payconiqHandler,
        private BancontactWebhookHandler $bancontactHandler,
    ) {}

    /**
     * POST /payments/webhooks/stripe
     */
    public function stripe(ServerRequestInterface $request): Response
    {
        $payload = (string) $request->getBody();
        $signature = $request->getHeaderLine('Stripe-Signature');

        try {
            $result = $this->stripeHandler->handle($payload, $signature);

            if (!$result['verified']) {
                return Response::json(['error' => 'Invalid signature'], 403);
            }

            return Response::json(['status' => 'ok', 'event_type' => $result['event_type']]);
        } catch (Throwable) {
            return Response::json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * POST /payments/webhooks/paypal
     */
    public function paypal(ServerRequestInterface $request): Response
    {
        $rawBody = (string) $request->getBody();

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $headers = [
            'PAYPAL-TRANSMISSION-ID' => $request->getHeaderLine('PAYPAL-TRANSMISSION-ID'),
            'PAYPAL-TRANSMISSION-TIME' => $request->getHeaderLine('PAYPAL-TRANSMISSION-TIME'),
            'PAYPAL-TRANSMISSION-SIG' => $request->getHeaderLine('PAYPAL-TRANSMISSION-SIG'),
            'PAYPAL-CERT-URL' => $request->getHeaderLine('PAYPAL-CERT-URL'),
        ];

        try {
            $result = $this->paypalHandler->handle($body, $rawBody, $headers);

            if (!$result['verified']) {
                return Response::json(['error' => 'Invalid signature'], 403);
            }

            return Response::json(['status' => 'ok', 'event_type' => $result['event_type']]);
        } catch (Throwable) {
            return Response::json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * POST /payments/webhooks/google-play
     */
    public function googlePlay(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->mobileHandler->handleGooglePlay($body);

            if ($result['status'] !== 'ok' && $result['status'] !== 'ignored') {
                return Response::json(['error' => $result['status']], 400);
            }

            return Response::json(['status' => $result['status']]);
        } catch (Throwable) {
            return Response::json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * POST /payments/webhooks/apple
     */
    public function apple(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->mobileHandler->handleAppleSns($body);

            if ($result['status'] !== 'ok') {
                return Response::json(['error' => $result['status']], 400);
            }

            return Response::json(['status' => 'ok']);
        } catch (Throwable) {
            return Response::json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * POST /payments/webhooks/payconiq
     */
    public function payconiq(ServerRequestInterface $request): Response
    {
        $payload = (string) $request->getBody();
        $signature = $request->getHeaderLine('X-Payconiq-Signature');

        try {
            $result = $this->payconiqHandler->handle($payload, $signature);

            if (!$result['verified']) {
                return Response::json(['error' => 'Invalid signature'], 403);
            }

            return Response::json([
                'status' => 'ok',
                'payment_id' => $result['payment_id'],
                'payment_status' => $result['status'],
            ]);
        } catch (Throwable) {
            return Response::json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * POST /payments/webhooks/bancontact
     */
    public function bancontact(ServerRequestInterface $request): Response
    {
        $payload = (string) $request->getBody();
        $signature = $request->getHeaderLine('Stripe-Signature');

        try {
            $result = $this->bancontactHandler->handle($payload, $signature);

            if (!$result['verified']) {
                return Response::json(['error' => 'Invalid signature'], 403);
            }

            return Response::json(['status' => 'ok', 'event_type' => $result['event_type']]);
        } catch (Throwable) {
            return Response::json(['error' => 'Processing failed'], 500);
        }
    }
}
