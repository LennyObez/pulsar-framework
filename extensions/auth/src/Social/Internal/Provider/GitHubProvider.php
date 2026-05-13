<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Internal\Provider;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\Social\Config\ProviderConfig;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderInterface;
use Pulsar\Extension\Auth\Social\Domain\OAuthRequest;
use Pulsar\Extension\Auth\Social\Domain\OAuthTokenSet;
use Pulsar\Extension\Auth\Social\Domain\SocialIdentity;
use Pulsar\Extension\Auth\Social\Exception\SsoException;

use function http_build_query;
use function implode;
use function is_array;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

#[Internal]
final readonly class GitHubProvider implements OAuthProviderInterface
{
    private const string DEFAULT_AUTH_URL = 'https://github.com/login/oauth/authorize';
    private const string DEFAULT_TOKEN_URL = 'https://github.com/login/oauth/access_token';
    private const string API_BASE = 'https://api.github.com';

    public function __construct(
        private ProviderConfig $config,
        private GitHubHttpClientInterface $httpClient,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'github';
    }

    #[Override]
    public function authorizationUrl(OAuthRequest $request): string
    {
        $authUrl = $this->config->authorizationUrl !== '' ? $this->config->authorizationUrl : self::DEFAULT_AUTH_URL;

        $params = [
            'client_id' => $this->config->clientId,
            'redirect_uri' => $request->redirectUri,
            'scope' => implode(' ', $request->scopes !== [] ? $request->scopes : ['read:user', 'user:email']),
            'state' => $request->state,
        ];

        return $authUrl . '?' . http_build_query($params);
    }

    #[Override]
    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): OAuthTokenSet
    {
        $tokenUrl = $this->config->tokenUrl !== '' ? $this->config->tokenUrl : self::DEFAULT_TOKEN_URL;

        $response = $this->httpClient->post($tokenUrl, [
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ], ['Accept' => 'application/json']);

        /** @var array<string, mixed> $data */
        $data = json_decode($response, true, 16, JSON_THROW_ON_ERROR);

        /** @var mixed $rawAccessToken */
        $rawAccessToken = $data['access_token'] ?? null;
        if (!is_string($rawAccessToken)) {
            throw SsoException::tokenExchangeFailed();
        }

        /** @var mixed $rawTokenType */
        $rawTokenType = $data['token_type'] ?? null;
        /** @var mixed $rawExpiresIn */
        $rawExpiresIn = $data['expires_in'] ?? null;
        /** @var mixed $rawRefreshToken */
        $rawRefreshToken = $data['refresh_token'] ?? null;

        return new OAuthTokenSet(
            accessToken: $rawAccessToken,
            tokenType: is_string($rawTokenType) ? $rawTokenType : 'bearer',
            expiresIn: (is_int($rawExpiresIn) || is_string($rawExpiresIn)) && is_numeric($rawExpiresIn) ? (int) $rawExpiresIn : null,
            refreshToken: is_string($rawRefreshToken) ? $rawRefreshToken : null,
        );
    }

    #[Override]
    public function mapIdentity(OAuthTokenSet $tokenSet): SocialIdentity
    {
        $userJson = $this->httpClient->get(
            self::API_BASE . '/user',
            ['Authorization' => 'Bearer ' . $tokenSet->accessToken, 'Accept' => 'application/json'],
        );

        /** @var array<string, mixed> $user */
        $user = json_decode($userJson, true, 16, JSON_THROW_ON_ERROR);

        /** @var mixed $rawEmail */
        $rawEmail = $user['email'] ?? null;
        $email = is_string($rawEmail) ? $rawEmail : null;

        // If email is not public, fetch from /user/emails endpoint
        if ($email === null || $email === '') {
            $email = $this->fetchPrimaryEmail($tokenSet->accessToken);
        }

        /** @var mixed $rawId */
        $rawId = $user['id'] ?? null;
        /** @var mixed $rawName */
        $rawName = $user['name'] ?? null;
        /** @var mixed $rawLogin */
        $rawLogin = $user['login'] ?? null;
        /** @var mixed $rawAvatarUrl */
        $rawAvatarUrl = $user['avatar_url'] ?? null;

        return new SocialIdentity(
            provider: 'github',
            providerUserId: is_scalar($rawId) ? (string) $rawId : '',
            email: $email,
            name: is_string($rawName) ? $rawName : (is_string($rawLogin) ? $rawLogin : null),
            avatarUrl: is_string($rawAvatarUrl) ? $rawAvatarUrl : null,
            rawAttributes: $user,
        );
    }

    private function fetchPrimaryEmail(string $accessToken): ?string
    {
        $emailsJson = $this->httpClient->get(
            self::API_BASE . '/user/emails',
            ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
        );

        $emails = json_decode($emailsJson, true, 16, JSON_THROW_ON_ERROR);

        if (!is_array($emails)) {
            return null;
        }
        /** @var list<array<string, mixed>> $emails */

        /** @var mixed $entry */
        foreach ($emails as $entry) {
            if (is_array($entry) && ($entry['primary'] ?? false) === true && is_string($rawEntryEmail = $entry['email'] ?? null)) {
                return $rawEntryEmail;
            }
        }

        return null;
    }
}
