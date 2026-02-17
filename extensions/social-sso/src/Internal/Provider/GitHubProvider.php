<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Internal\Provider;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\SocialSso\Config\ProviderConfig;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderInterface;
use Pulsar\Extension\SocialSso\Domain\OAuthRequest;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Exception\SsoException;

use function http_build_query;
use function implode;
use function is_array;
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

        if (!is_string($data['access_token'] ?? null)) {
            throw SsoException::tokenExchangeFailed();
        }

        return new OAuthTokenSet(
            accessToken: $data['access_token'],
            tokenType: is_string($data['token_type'] ?? null) ? $data['token_type'] : 'bearer',
            expiresIn: is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : null,
            refreshToken: is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null,
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

        $email = is_string($user['email'] ?? null) ? $user['email'] : null;

        // If email is not public, fetch from /user/emails endpoint
        if ($email === null || $email === '') {
            $email = $this->fetchPrimaryEmail($tokenSet->accessToken);
        }

        return new SocialIdentity(
            provider: 'github',
            providerUserId: is_scalar($user['id'] ?? null) ? (string) $user['id'] : '',
            email: $email,
            name: is_string($user['name'] ?? null) ? $user['name'] : (is_string($user['login'] ?? null) ? $user['login'] : null),
            avatarUrl: is_string($user['avatar_url'] ?? null) ? $user['avatar_url'] : null,
            rawAttributes: $user,
        );
    }

    private function fetchPrimaryEmail(string $accessToken): ?string
    {
        $emailsJson = $this->httpClient->get(
            self::API_BASE . '/user/emails',
            ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
        );

        /** @var list<array<string, mixed>> $emails */
        $emails = json_decode($emailsJson, true, 16, JSON_THROW_ON_ERROR);

        if (!is_array($emails)) {
            return null;
        }

        foreach ($emails as $entry) {
            if (is_array($entry) && ($entry['primary'] ?? false) === true && is_string($entry['email'] ?? null)) {
                return $entry['email'];
            }
        }

        return null;
    }
}
