<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Processes incoming analytics events from the tracker.
 */
#[Api(since: '1.0.0')]
interface TrackingServiceInterface
{
    /**
     * Track a page view event.
     *
     * @param array<string, mixed> $payload Decoded tracker payload
     */
    public function trackPageView(ServerRequestInterface $request, array $payload): void;

    /**
     * Track a custom event.
     *
     * @param array<string, mixed> $payload Decoded tracker payload
     */
    public function trackEvent(ServerRequestInterface $request, array $payload): void;
}
