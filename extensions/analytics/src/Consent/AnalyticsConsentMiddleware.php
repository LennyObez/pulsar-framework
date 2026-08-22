<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Consent;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\DataProtection\ConsentManagerInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Internal\Security\VisitorConsentIdentity;

use function str_contains;
use function str_replace;
use function strtolower;

/**
 * PSR-15 middleware that enforces analytics consent when required.
 *
 * When `privacy.requireConsent` is enabled:
 * - Checks for consent via the X-Analytics-Consent header (set by client JS)
 * - Verifies server-side consent via ConsentManagerInterface
 * - If consent is not granted, injects the consent banner HTML before </body>
 * - Adds a request attribute 'analytics.consent_granted' for downstream use
 *
 * Only processes responses with text/html content type.
 */
#[Internal(reason: 'Analytics consent middleware; wired by extension boot')]
final readonly class AnalyticsConsentMiddleware implements MiddlewareInterface
{
    private const string CONSENT_PURPOSE = 'analytics';
    private const string CONSENT_HEADER = 'X-Analytics-Consent';

    public function __construct(
        private AnalyticsConfig $config,
        private ConsentManagerInterface $consentManager,
        private VisitorConsentIdentity $consentIdentity,
        private AnalyticsConsentBanner $banner,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->privacy->requireConsent) {
            return $handler->handle(
                $request->withAttribute('analytics.consent_granted', true),
            );
        }

        $visitorHash = $this->resolveVisitorHash($request);
        $consentGranted = $this->isConsentGranted($request, $visitorHash);

        $request = $request->withAttribute('analytics.consent_granted', $consentGranted);
        $response = $handler->handle($request);

        if ($consentGranted) {
            return $response;
        }

        // Only inject banner into HTML responses
        $contentType = $response->getHeaderLine('Content-Type');

        if (!str_contains(strtolower($contentType), 'text/html')) {
            return $response;
        }

        return $this->injectBanner($response);
    }

    /**
     * Determine whether the visitor has granted analytics consent.
     *
     * Checks client-side header first (fast path), then falls back to
     * server-side consent record lookup.
     */
    private function isConsentGranted(ServerRequestInterface $request, string $visitorHash): bool
    {
        $clientConsent = $request->getHeaderLine(self::CONSENT_HEADER);

        if ($clientConsent === 'granted') {
            // Verify against server-side record to prevent spoofing
            return $this->consentManager->hasConsent($visitorHash, self::CONSENT_PURPOSE);
        }

        if ($clientConsent === 'declined') {
            return false;
        }

        // No client header: check server-side record
        return $this->consentManager->hasConsent($visitorHash, self::CONSENT_PURPOSE);
    }

    /**
     * The consent subject for this request — the same identifier the consent
     * grant endpoint and the tracker derive, so consent granted by a visitor is
     * recognised here (and, in turn, the tracker records the hit).
     */
    private function resolveVisitorHash(ServerRequestInterface $request): string
    {
        return $this->consentIdentity->forRequest($request);
    }

    /**
     * Inject the consent banner HTML before the closing </body> tag.
     */
    private function injectBanner(ResponseInterface $response): ResponseInterface
    {
        $body = (string) $response->getBody();
        $bannerHtml = $this->banner->render();

        $injected = str_replace('</body>', $bannerHtml . '</body>', $body);

        $stream = new \Pulsar\Http\Message\StringStream($injected);

        return $response
            ->withBody($stream)
            ->withHeader('Content-Length', (string) $stream->getSize());
    }
}
