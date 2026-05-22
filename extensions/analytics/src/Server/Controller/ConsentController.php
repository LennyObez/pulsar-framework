<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\DataProtection\ConsentManagerInterface;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Http\Message\Response;
use Pulsar\Security\Crypto\Hmac;

use function is_string;

/**
 * Handles analytics consent grant and revocation requests.
 *
 * Identifies visitors by a keyed hash of their IP and user agent,
 * consistent with the privacy-preserving approach used throughout
 * the analytics extension (no cookies, no PII storage).
 */
#[Internal(reason: 'Analytics consent API controller')]
final readonly class ConsentController
{
    private const string CONSENT_PURPOSE = 'analytics';

    public function __construct(
        private ConsentManagerInterface $consentManager,
        private AnalyticsKeyManager $keyManager,
    ) {}

    /**
     * Grant analytics consent for the current visitor.
     *
     * POST /plsr/consent/grant
     */
    public function grant(ServerRequestInterface $request): Response
    {
        $visitorHash = $this->resolveVisitorHash($request);

        $this->consentManager->grant($visitorHash, self::CONSENT_PURPOSE);

        return Response::noContent();
    }

    /**
     * Revoke analytics consent for the current visitor.
     *
     * POST /plsr/consent/revoke
     */
    public function revoke(ServerRequestInterface $request): Response
    {
        $visitorHash = $this->resolveVisitorHash($request);

        $this->consentManager->revoke($visitorHash, self::CONSENT_PURPOSE);

        return Response::noContent();
    }

    /**
     * Generate a stable visitor hash for consent identification.
     *
     * Uses HMAC with a KDF-derived key, producing a hash that cannot be
     * reversed to recover IP or user agent. The 'consent' salt ensures
     * this hash is distinct from the daily-rotating analytics visitor ID.
     */
    private function resolveVisitorHash(ServerRequestInterface $request): string
    {
        /** @var mixed $rawIp */
        $rawIp = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip = is_string($rawIp) ? $rawIp : '0.0.0.0';
        $userAgent = $request->getHeaderLine('User-Agent');
        $key = $this->keyManager->visitorKey();

        return Hmac::computeHex($ip . '|' . $userAgent . '|consent', $key);
    }
}
