<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Internal\Provider;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderInterface;
use Pulsar\Extension\SocialSso\Domain\OAuthRequest;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Exception\SsoException;

use function array_key_exists;
use function http_build_query;
use function implode;

/**
 * Local mock OAuth provider for testing and development.
 *
 * Returns pre-configured tokens and identities for given authorization codes.
 * All URLs are synthetic and no real HTTP calls are made.
 */
#[Internal]
final class LocalMockProvider implements OAuthProviderInterface
{
    /** @var array<string, OAuthTokenSet> */
    private array $tokens = [];

    /** @var array<string, SocialIdentity> */
    private array $identities = [];

    public function __construct(
        private readonly string $name = 'mock',
    ) {}

    /**
     * Pre-configure a token set to be returned when the given authorization code is exchanged.
     */
    public function setTokenForCode(string $code, OAuthTokenSet $tokenSet): void
    {
        $this->tokens[$code] = $tokenSet;
    }

    /**
     * Pre-configure a social identity to be returned when the given authorization code is exchanged.
     */
    public function setIdentityForCode(string $code, SocialIdentity $identity): void
    {
        $this->identities[$code] = $identity;
    }

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    #[Override]
    public function authorizationUrl(OAuthRequest $request): string
    {
        $params = [
            'provider' => $this->name,
            'redirect_uri' => $request->redirectUri,
            'scope' => implode(' ', $request->scopes),
            'state' => $request->state,
            'response_type' => 'code',
        ];

        if ($request->nonce !== null) {
            $params['nonce'] = $request->nonce;
        }

        if ($request->codeChallenge !== null) {
            $params['code_challenge'] = $request->codeChallenge;
            $params['code_challenge_method'] = $request->codeChallengeMethod;
        }

        return 'https://mock.local/authorize?' . http_build_query($params);
    }

    #[Override]
    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): OAuthTokenSet
    {
        if (!array_key_exists($code, $this->tokens)) {
            throw SsoException::tokenExchangeFailed();
        }

        return $this->tokens[$code];
    }

    #[Override]
    public function mapIdentity(OAuthTokenSet $tokenSet): SocialIdentity
    {
        // Look up identity by matching the access token against stored code-based identities
        foreach ($this->tokens as $code => $storedToken) {
            if ($storedToken->accessToken === $tokenSet->accessToken && array_key_exists($code, $this->identities)) {
                return $this->identities[$code];
            }
        }

        // Return a default mock identity when no pre-configured mapping exists
        return new SocialIdentity(
            provider: $this->name,
            providerUserId: 'mock-user-' . $tokenSet->accessToken,
            email: 'mock@example.com',
            name: 'Mock User',
        );
    }
}
