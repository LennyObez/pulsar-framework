<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivateTokenHeaderParser;
use Pulsar\Security\AntiSpam\Risk\RiskBypassProviderInterface;

/**
 * Bypasses adaptive risk scoring when the request carries a valid Private
 * Access Token (Privacy Pass).
 *
 * A redeemed token proves the client passed an attester's checks without
 * revealing its identity, so it is a strong signal of a legitimate client and
 * should skip the challenge entirely. An absent or invalid token does not
 * bypass — the request falls through to normal scoring.
 */
#[Internal]
final readonly class PrivateTokenBypassProvider implements RiskBypassProviderInterface
{
    public function __construct(
        private PrivateAccessTokenVerifier $verifier,
        private TokenChallenge $challenge,
    ) {}

    #[Override]
    public function shouldBypass(ServerRequestInterface $request): bool
    {
        $token = PrivateTokenHeaderParser::fromRequest($request);
        if ($token === null) {
            return false;
        }

        return $this->verifier->verify($token, $this->challenge);
    }
}
