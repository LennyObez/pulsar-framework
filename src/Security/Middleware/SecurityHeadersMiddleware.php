<?php

declare(strict_types=1);

namespace Pulsar\Security\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Http\Http3\AltSvcHeader;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function explode;
use function inet_pton;
use function intdiv;
use function is_string;
use function ord;
use function str_contains;
use function strlen;
use function substr;

/**
 * Middleware that applies configured security headers to every response.
 *
 * Adds headers like X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
 * CSP, Cross-Origin headers, and conditionally HSTS for secure requests.
 *
 * The X-Forwarded-Proto header is only trusted when the client IP is in the
 * configured trusted proxies list, preventing header spoofing from untrusted
 * clients. See ADR-0026 (deploy config) and CFR-71.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $trustedProxies;

    /**
     * @param list<string> $trustedProxies IP addresses or CIDR ranges of trusted proxies
     * @param AltSvcHeader|null $altSvc Alt-Svc header for HTTP/3 advertisement
     */
    public function __construct(
        private SecurityHeadersConfig $config,
        array $trustedProxies = [],
        private ?AltSvcHeader $altSvc = null,
    ) {
        $this->trustedProxies = $trustedProxies;
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->config->effectiveHeaders() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        // HSTS is emitted only on secure requests (RFC 6797 §7.2). A literal
        // Strict-Transport-Security in `headers` takes precedence over the
        // structured HstsConfig — both resolved by effectiveHstsHeader().
        $hsts = $this->config->effectiveHstsHeader();
        if ($hsts !== null && $this->isSecureRequest($request)) {
            $response = $response->withHeader('Strict-Transport-Security', $hsts);
        }

        // Alt-Svc: advertise HTTP/3 support when configured
        if ($this->altSvc !== null && !$this->altSvc->isEmpty()) {
            $response = $response->withHeader('Alt-Svc', $this->altSvc->toHeaderValue());
        }

        // Clear-Site-Data: wipe browser-side residue on session destruction (logout)
        if ($request->getAttribute(self::CLEAR_SITE_DATA_ATTR) === true) {
            $response = $response->withHeader(
                'Clear-Site-Data',
                '"cache", "cookies", "storage"',
            );
        }

        return $response;
    }

    /**
     * Request attribute key used to trigger Clear-Site-Data header emission.
     *
     * Set this attribute to `true` on the request during logout/session
     * destruction to instruct the browser to clear cached data.
     */
    public const string CLEAR_SITE_DATA_ATTR = '_pulsar_clear_site_data';

    private function isSecureRequest(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        if ($this->trustedProxies === []) {
            return false;
        }

        /** @var mixed $clientIpRaw */
        $clientIpRaw = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $clientIp = is_string($clientIpRaw) ? $clientIpRaw : '';

        if ($clientIp === '' || !$this->isFromTrustedProxy($clientIp)) {
            return false;
        }

        return $request->getHeaderLine('X-Forwarded-Proto') === 'https';
    }

    private function isFromTrustedProxy(string $clientIp): bool
    {
        foreach ($this->trustedProxies as $trusted) {
            if ($trusted === $clientIp) {
                return true;
            }

            if (str_contains($trusted, '/') && $this->ipInCidr($clientIp, $trusted)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        $subnet = $parts[0];
        $bits = isset($parts[1]) ? (int) $parts[1] : 32;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Ensure same address family
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        // Build bitmask and compare
        $fullBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        // Compare full bytes
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        // Compare remaining bits
        if ($remainderBits > 0 && $fullBytes < strlen($ipBin)) {
            $mask = 0xFF << (8 - $remainderBits) & 0xFF;

            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
