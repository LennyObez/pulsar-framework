<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Features\InitiateLogin;

use Pulsar\Extension\Auth\Social\Config\SocialSsoConfig;
use Pulsar\Extension\Auth\Social\Contracts\NonceVerifierInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\Auth\Social\Domain\OAuthRequest;
use Pulsar\Extension\Auth\Social\Domain\PkceChallenge;
use Pulsar\Extension\Auth\Social\Internal\Session\SessionOAuthStateManager;

/**
 * Initiates the OAuth authorization flow for a social login.
 *
 * Generates CSRF state, optional PKCE challenge and OIDC nonce,
 * then builds the authorization URL via the provider adapter.
 */
final readonly class InitiateLoginHandler
{
    public function __construct(
        private OAuthProviderRegistryInterface $providerRegistry,
        private OAuthStateManagerInterface $stateManager,
        private NonceVerifierInterface $nonceVerifier,
        private SocialSsoConfig $config,
    ) {}

    public function handle(InitiateLoginRequest $request): InitiateLoginResult
    {
        $provider = $this->providerRegistry->get($request->providerName);
        $providerConfig = $this->config->providers[$request->providerName];

        $state = $this->stateManager->generate();

        $pkceChallenge = null;
        if ($this->config->requirePkce) {
            $pkceChallenge = PkceChallenge::generate();

            if ($this->stateManager instanceof SessionOAuthStateManager) {
                $this->stateManager->storePkceVerifier($state, $pkceChallenge->verifier);
            }
        }

        $nonce = null;
        if ($this->config->requireNonce && $providerConfig->type === 'oidc') {
            $nonce = $this->nonceVerifier->generate();
        }

        $redirectUri = $request->redirectUri ?? $providerConfig->redirectUri ?? '';

        $oauthRequest = new OAuthRequest(
            redirectUri: $redirectUri,
            scopes: $providerConfig->scopes,
            state: $state,
            nonce: $nonce,
            codeChallenge: $pkceChallenge?->challenge,
        );

        $authorizationUrl = $provider->authorizationUrl($oauthRequest);

        return new InitiateLoginResult(
            authorizationUrl: $authorizationUrl,
            state: $state,
            pkceChallenge: $pkceChallenge,
        );
    }
}
