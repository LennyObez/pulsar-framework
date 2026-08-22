<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\DataProtection\ConsentManagerInterface;
use Pulsar\Extension\Analytics\Internal\Security\VisitorConsentIdentity;
use Pulsar\Http\Message\Response;

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
        private VisitorConsentIdentity $consentIdentity,
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
     * The consent subject for this request — the same identifier the banner
     * middleware and the tracker derive, so a grant here is recognised there.
     */
    private function resolveVisitorHash(ServerRequestInterface $request): string
    {
        return $this->consentIdentity->forRequest($request);
    }
}
