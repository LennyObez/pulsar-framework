<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Security;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Security\Crypto\Hmac;

use function array_map;
use function count;
use function explode;
use function in_array;
use function is_string;
use function trim;

/**
 * Single source of truth for the analytics consent subject identifier.
 *
 * Consent is granted (ConsentController), enforced at the banner
 * (AnalyticsConsentMiddleware) and checked before tracking (TrackingService).
 * All three MUST derive the *same* identifier from a request or the grant a
 * visitor gives will never match the record the tracker looks up — silently
 * dropping every tracked hit whenever `requireConsent` is enabled (the GDPR
 * default). Historically each site rolled its own: two hashed a bare
 * REMOTE_ADDR while the tracker looked up the raw, un-hashed IP, and none
 * agreed behind a proxy. Routing all three through this class removes the
 * divergence by construction.
 *
 * The identifier is HMAC(clientIp | userAgent | 'consent') under the KDF-derived
 * visitor key: it cannot be reversed to recover the IP or user agent, and the
 * 'consent' salt keeps it distinct from the daily-rotating analytics visitor id.
 */
#[Internal(reason: 'Analytics consent subject derivation; shared by grant, banner and tracker')]
final readonly class VisitorConsentIdentity
{
    public function __construct(
        private AnalyticsKeyManager $keyManager,
        private AnalyticsConfig $config,
    ) {}

    /**
     * The consent subject identifier for a request: the canonical client IP and
     * user agent, hashed under the visitor key.
     */
    public function forRequest(ServerRequestInterface $request): string
    {
        return $this->compute($this->clientIp($request), $request->getHeaderLine('User-Agent'));
    }

    /**
     * HMAC the (clientIp | userAgent | 'consent') tuple under the visitor key.
     */
    public function compute(string $clientIp, string $userAgent): string
    {
        return Hmac::computeHex($clientIp . '|' . $userAgent . '|consent', $this->keyManager->visitorKey());
    }

    /**
     * Extract the client IP, trusting X-Forwarded-For only from configured
     * trusted proxies. Without that check any client could spoof their IP via
     * XFF and forge a consent subject other than their own.
     */
    public function clientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        /** @var mixed $raw */
        $raw = $serverParams['REMOTE_ADDR'] ?? null;
        $remoteAddr = is_string($raw) ? $raw : '127.0.0.1';

        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');

        if ($forwardedFor !== '' && $this->isTrustedProxy($remoteAddr)) {
            $ips = array_map(trim(...), explode(',', $forwardedFor));

            // Walk right-to-left: rightmost IPs are closest to the server (most
            // trusted). The first non-proxy hop is the real client.
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                if (!$this->isTrustedProxy($ips[$i])) {
                    return $ips[$i];
                }
            }

            // Every hop is a trusted proxy: fall back to the leftmost (client).
            if ($ips !== []) {
                return $ips[0];
            }
        }

        return $remoteAddr;
    }

    private function isTrustedProxy(string $remoteAddr): bool
    {
        if ($this->config->trustedProxies === []) {
            return false;
        }

        return in_array($remoteAddr, $this->config->trustedProxies, true);
    }
}
