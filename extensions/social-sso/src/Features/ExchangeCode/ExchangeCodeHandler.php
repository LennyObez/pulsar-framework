<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Features\ExchangeCode;

use Pulsar\Extension\SocialSso\Config\SocialSsoConfig;
use Pulsar\Extension\SocialSso\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\NonceVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;
use Pulsar\Extension\SocialSso\Domain\IdTokenVerificationContext;
use Pulsar\Extension\SocialSso\Exception\SsoException;

/**
 * Exchanges an OAuth authorization code for tokens.
 *
 * Verifies the CSRF state token, retrieves the PKCE verifier,
 * exchanges the code via the provider, and optionally verifies
 * the OIDC ID token.
 */
final readonly class ExchangeCodeHandler
{
    public function __construct(
        private OAuthProviderRegistryInterface $providerRegistry,
        private OAuthStateManagerInterface $stateManager,
        private NonceVerifierInterface $nonceVerifier,
        private IdTokenVerifierInterface $idTokenVerifier,
        private SocialSsoConfig $config,
    ) {}

    /**
     * @throws SsoException
     */
    public function handle(ExchangeCodeRequest $request): ExchangeCodeResult
    {
        if (!$this->stateManager->verify($request->state)) {
            throw SsoException::invalidState();
        }

        $codeVerifier = $this->stateManager->retrievePkceVerifier($request->state);

        $provider = $this->providerRegistry->get($request->providerName);
        $providerConfig = $this->config->providers[$request->providerName];

        $redirectUri = $providerConfig->redirectUri ?? '';

        $tokenSet = $provider->exchangeCode($request->code, $redirectUri, $codeVerifier);

        $verifiedClaims = null;

        if ($tokenSet->idToken !== null) {
            if ($providerConfig->type === 'oauth2' && !$providerConfig->allowUnverifiedIdToken) {
                throw SsoException::unexpectedIdToken();
            }

            $verifiedClaims = $this->verifyIdToken(
                $tokenSet->idToken,
                $providerConfig->clientId,
                $providerConfig->issuer ?? '',
                $providerConfig->maxClockSkewSeconds,
            );

            if ($this->config->requireNonce && $verifiedClaims->nonce !== null) {
                if (!$this->nonceVerifier->verify($verifiedClaims->nonce, $verifiedClaims)) {
                    throw SsoException::invalidNonce();
                }
            }
        }

        return new ExchangeCodeResult(
            tokenSet: $tokenSet,
            verifiedClaims: $verifiedClaims,
        );
    }

    private function verifyIdToken(
        string $idToken,
        string $clientId,
        string $issuer,
        int $maxClockSkewSeconds,
    ): IdTokenClaims {
        $context = new IdTokenVerificationContext(
            clientId: $clientId,
            issuer: $issuer,
            maxClockSkewSeconds: $maxClockSkewSeconds,
        );

        return $this->idTokenVerifier->verify($idToken, $context);
    }
}
