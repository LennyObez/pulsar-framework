<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\OAuth2\Client\OAuthClient;
use Pulsar\Extension\OAuth2\Contract\ScopeRepositoryInterface;
use Pulsar\Extension\OAuth2\Exception\OAuth2Exception;
use Pulsar\Extension\OAuth2\Grant\AuthorizationCodeGrant;
use Pulsar\Extension\OAuth2\Grant\ClientCredentialsGrant;
use Pulsar\Extension\OAuth2\Grant\RefreshTokenGrant;
use Pulsar\Extension\OAuth2\Token\AccessToken;
use Pulsar\Extension\OAuth2\Token\AuthorizationCode;
use Pulsar\Extension\OAuth2\Token\InMemoryAccessTokenRepository;
use Pulsar\Extension\OAuth2\Token\InMemoryAuthorizationCodeRepository;
use Pulsar\Extension\OAuth2\Token\InMemoryRefreshTokenRepository;
use Pulsar\Extension\OAuth2\Token\RefreshToken;
use Pulsar\Extension\OAuth2\Token\Scope;

/**
 * Adversarial security tests for the OAuth2 extension.
 *
 * Each test simulates a specific attack and verifies the implementation
 * correctly prevents it. These tests are written from an attacker's perspective.
 */
#[CoversClass(AuthorizationCodeGrant::class)]
#[CoversClass(RefreshTokenGrant::class)]
#[CoversClass(ClientCredentialsGrant::class)]
#[CoversClass(InMemoryRefreshTokenRepository::class)]
#[CoversClass(InMemoryAuthorizationCodeRepository::class)]
#[CoversClass(InMemoryAccessTokenRepository::class)]
final class AdversarialTest extends TestCase
{
    private InMemoryAuthorizationCodeRepository $codeRepo;
    private InMemoryAccessTokenRepository $accessTokenRepo;
    private InMemoryRefreshTokenRepository $refreshTokenRepo;
    private AuditLoggerInterface $auditLogger;
    private ScopeRepositoryInterface $scopeRepo;
    private AuthorizationCodeGrant $authCodeGrant;
    private RefreshTokenGrant $refreshTokenGrant;

    protected function setUp(): void
    {
        $this->codeRepo = new InMemoryAuthorizationCodeRepository();
        $this->accessTokenRepo = new InMemoryAccessTokenRepository();
        $this->refreshTokenRepo = new InMemoryRefreshTokenRepository();
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->scopeRepo = $this->createScopeRepository();

        $this->authCodeGrant = new AuthorizationCodeGrant(
            codeRepository: $this->codeRepo,
            accessTokenRepository: $this->accessTokenRepo,
            refreshTokenRepository: $this->refreshTokenRepo,
            scopeRepository: $this->scopeRepo,
            auditLogger: $this->auditLogger,
        );

        $this->refreshTokenGrant = new RefreshTokenGrant(
            refreshTokenRepository: $this->refreshTokenRepo,
            accessTokenRepository: $this->accessTokenRepo,
            scopeRepository: $this->scopeRepo,
            auditLogger: $this->auditLogger,
        );
    }

    // ========================================================================
    // Attack Vector 1: Authorization Code Replay
    // ========================================================================

    #[Test]
    public function authorizationCodeCannotBeUsedTwice(): void
    {
        $client = $this->createConfidentialClient('client-a');
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $codeChallenge = $this->computeS256Challenge($codeVerifier);

        $authCode = $this->authCodeGrant->createAuthorizationCode(
            client: $client,
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
        );

        // First use: legitimate
        $request = $this->createTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $authCode->codeValue,
            'redirect_uri' => 'https://app.example.com/callback',
            'code_verifier' => $codeVerifier,
        ]);
        $this->authCodeGrant->handleTokenRequest($request, $client);

        // Second use: attacker replays the code
        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('invalid, expired, or already used');
        $this->authCodeGrant->handleTokenRequest($request, $client);
    }

    // ========================================================================
    // Attack Vector 2: PKCE Bypass Attempts
    // ========================================================================

    #[Test]
    public function pkceBypassWithWrongVerifierRejected(): void
    {
        $client = $this->createConfidentialClient('client-a');
        $legitimateVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $codeChallenge = $this->computeS256Challenge($legitimateVerifier);

        $authCode = $this->authCodeGrant->createAuthorizationCode(
            client: $client,
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
        );

        // Attacker intercepted the code but does not know the verifier
        $attackerVerifier = 'attacker-does-not-know-the-real-verifier-value1';

        $request = $this->createTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $authCode->codeValue,
            'redirect_uri' => 'https://app.example.com/callback',
            'code_verifier' => $attackerVerifier,
        ]);

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('PKCE code_verifier is invalid');
        $this->authCodeGrant->handleTokenRequest($request, $client);
    }

    #[Test]
    public function pkceBypassWithEmptyVerifierRejected(): void
    {
        $client = $this->createConfidentialClient('client-a');
        $codeChallenge = $this->computeS256Challenge('real-verifier-that-was-used-in-auth-request12');

        $authCode = $this->authCodeGrant->createAuthorizationCode(
            client: $client,
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
        );

        // Attacker sends empty code_verifier
        $request = $this->createTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $authCode->codeValue,
            'redirect_uri' => 'https://app.example.com/callback',
            'code_verifier' => '',
        ]);

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Missing required parameter: code_verifier');
        $this->authCodeGrant->handleTokenRequest($request, $client);
    }

    #[Test]
    public function plainChallengeMethodRejectedAtCodeCreation(): void
    {
        $client = $this->createConfidentialClient('client-a');

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Only S256');
        $this->authCodeGrant->createAuthorizationCode(
            client: $client,
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'plain-challenge',
            codeChallengeMethod: 'plain',
        );
    }

    // ========================================================================
    // Attack Vector 3: Cross-Client Token Theft
    // ========================================================================

    #[Test]
    public function authorizationCodeFromClientACannotBeRedeemedByClientB(): void
    {
        $clientA = $this->createConfidentialClient('client-a');
        $clientB = $this->createConfidentialClient('client-b');
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $codeChallenge = $this->computeS256Challenge($codeVerifier);

        // Code issued to client-a
        $authCode = $this->authCodeGrant->createAuthorizationCode(
            client: $clientA,
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
        );

        // Client-b tries to redeem it
        $request = $this->createTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $authCode->codeValue,
            'redirect_uri' => 'https://app.example.com/callback',
            'code_verifier' => $codeVerifier,
        ]);

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('not issued to this client');
        $this->authCodeGrant->handleTokenRequest($request, $clientB);
    }

    #[Test]
    public function refreshTokenFromClientACannotBeUsedByClientB(): void
    {
        $clientA = $this->createConfidentialClient('client-a');
        $clientB = $this->createConfidentialClient('client-b');

        // Refresh token issued to client-a
        $refreshToken = $this->createAndPersistRefreshToken('client-a', 'user-42', 'rt-value-secret');

        // Client-b tries to use it
        $request = $this->createTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => 'rt-value-secret',
        ]);

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('not issued to this client');
        $this->refreshTokenGrant->handleTokenRequest($request, $clientB);
    }

    // ========================================================================
    // Attack Vector 4: Refresh Token Replay Detection
    // ========================================================================

    #[Test]
    public function replayOfRotatedOutRefreshTokenRevokesEntireFamily(): void
    {
        $client = $this->createConfidentialClient('client-a');

        // Create initial refresh token
        $initialTokenValue = bin2hex(random_bytes(32));
        $refreshToken = new RefreshToken(
            id: 'rt-001',
            clientId: 'client-a',
            subjectId: 'user-42',
            sessionId: 'session-1',
            familyId: 'family-1',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $initialTokenValue,
        );
        $this->refreshTokenRepo->persist($refreshToken);

        // Legitimate rotation: consume the initial token
        $request1 = $this->createTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $initialTokenValue,
        ]);
        $response1 = $this->refreshTokenGrant->handleTokenRequest($request1, $client);

        // Attacker replays the original (now-consumed) token
        $request2 = $this->createTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $initialTokenValue,
        ]);

        try {
            $this->refreshTokenGrant->handleTokenRequest($request2, $client);
            self::fail('Expected OAuth2Exception for replayed refresh token');
        } catch (OAuth2Exception $e) {
            self::assertStringContainsString('invalid, expired, or already used', $e->getMessage());
        }

        // Verify replay was detected
        self::assertTrue($this->refreshTokenRepo->wasReplayDetected());

        // Verify the new token from rotation is also revoked (family revoked)
        $newRefreshTokenRequest = $this->createTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $response1->refreshToken,
        ]);

        $this->expectException(OAuth2Exception::class);
        $this->refreshTokenGrant->handleTokenRequest($newRefreshTokenRequest, $client);
    }

    // ========================================================================
    // Attack Vector 5: Redirect URI Manipulation
    // ========================================================================

    #[Test]
    public function codeCreationWithUnregisteredRedirectUriRejected(): void
    {
        $client = $this->createConfidentialClient('client-a');

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Invalid redirect_uri');
        $this->authCodeGrant->createAuthorizationCode(
            client: $client,
            subjectId: 'user-42',
            redirectUri: 'https://evil.example.com/steal-code',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
        );
    }

    #[Test]
    public function codeExchangeWithMismatchedRedirectUriRejected(): void
    {
        $client = $this->createConfidentialClient('client-a');
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $codeChallenge = $this->computeS256Challenge($codeVerifier);

        $authCode = $this->authCodeGrant->createAuthorizationCode(
            client: $client,
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
        );

        // Attacker changes redirect_uri at token exchange
        $request = $this->createTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $authCode->codeValue,
            'redirect_uri' => 'https://evil.example.com/steal-tokens',
            'code_verifier' => $codeVerifier,
        ]);

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('redirect_uri does not match');
        $this->authCodeGrant->handleTokenRequest($request, $client);
    }

    // ========================================================================
    // Attack Vector 6: Scope Escalation
    // ========================================================================

    #[Test]
    public function refreshTokenCannotEscalateScopes(): void
    {
        $client = $this->createConfidentialClient('client-a');

        // Token issued with 'openid' scope only
        $tokenValue = bin2hex(random_bytes(32));
        $refreshToken = new RefreshToken(
            id: 'rt-scope-001',
            clientId: 'client-a',
            subjectId: 'user-42',
            sessionId: 'session-1',
            familyId: 'family-scope',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->refreshTokenRepo->persist($refreshToken);

        // Attacker requests additional scopes
        $request = $this->createTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokenValue,
            'scope' => 'openid admin:write',
        ]);

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('not included in the original grant');
        $this->refreshTokenGrant->handleTokenRequest($request, $client);
    }

    // ========================================================================
    // Attack Vector 7: Token Revocation
    // ========================================================================

    #[Test]
    public function revokedAccessTokenNotReturned(): void
    {
        $tokenValue = bin2hex(random_bytes(32));
        $accessToken = new AccessToken(
            id: 'at-revoke-001',
            clientId: 'client-a',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );

        $this->accessTokenRepo->persist($accessToken);

        // Token is valid before revocation
        $introspected = $this->accessTokenRepo->introspect($tokenValue);
        self::assertNotNull($introspected);

        // Revoke the token
        $this->accessTokenRepo->revoke('at-revoke-001');

        // Token should not be returned after revocation
        $introspected = $this->accessTokenRepo->introspect($tokenValue);
        self::assertNull($introspected);
    }

    #[Test]
    public function revokedRefreshTokenCannotBeConsumed(): void
    {
        $tokenValue = bin2hex(random_bytes(32));
        $refreshToken = new RefreshToken(
            id: 'rt-revoke-001',
            clientId: 'client-a',
            subjectId: 'user-42',
            sessionId: 'session-1',
            familyId: 'family-revoke',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->refreshTokenRepo->persist($refreshToken);

        // Revoke the token
        $this->refreshTokenRepo->revoke('rt-revoke-001');

        // Attempt to consume
        $consumed = $this->refreshTokenRepo->consume($tokenValue);
        self::assertNull($consumed);
    }

    #[Test]
    public function familyRevocationInvalidatesAllTokensInFamily(): void
    {
        $familyId = 'family-cascade';

        $tokenA = new RefreshToken(
            id: 'rt-family-a',
            clientId: 'client-a',
            subjectId: 'user-42',
            sessionId: 'session-1',
            familyId: $familyId,
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'token-a-value',
        );

        $tokenB = new RefreshToken(
            id: 'rt-family-b',
            clientId: 'client-a',
            subjectId: 'user-42',
            sessionId: 'session-1',
            familyId: $familyId,
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'token-b-value',
        );

        $this->refreshTokenRepo->persist($tokenA);
        $this->refreshTokenRepo->persist($tokenB);

        // Revoke entire family
        $this->refreshTokenRepo->revokeFamily($familyId);

        // Both tokens should be revoked
        self::assertTrue($this->refreshTokenRepo->isRevoked('rt-family-a'));
        self::assertTrue($this->refreshTokenRepo->isRevoked('rt-family-b'));
        self::assertNull($this->refreshTokenRepo->consume('token-a-value'));
        self::assertNull($this->refreshTokenRepo->consume('token-b-value'));
    }

    // ========================================================================
    // Attack Vector 8: Client Credentials Abuse
    // ========================================================================

    #[Test]
    public function publicClientCannotUseClientCredentialsGrant(): void
    {
        $publicClient = new OAuthClient(
            id: 'public-client',
            name: 'Public SPA',
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code', 'client_credentials'],
            scopes: ['openid'],
            confidential: false,
        );

        $grant = new ClientCredentialsGrant(
            accessTokenRepository: $this->accessTokenRepo,
            scopeRepository: $this->scopeRepo,
            auditLogger: $this->auditLogger,
        );

        $request = $this->createTokenRequest([
            'grant_type' => 'client_credentials',
        ]);

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Only confidential clients');
        $grant->handleTokenRequest($request, $publicClient);
    }

    // ========================================================================
    // Attack Vector 9: Expired Token Abuse
    // ========================================================================

    #[Test]
    public function expiredAccessTokenNotIntrospectable(): void
    {
        $tokenValue = bin2hex(random_bytes(32));
        $accessToken = new AccessToken(
            id: 'at-expired-001',
            clientId: 'client-a',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-2 hours'),
            tokenValue: $tokenValue,
        );

        $this->accessTokenRepo->persist($accessToken);

        $introspected = $this->accessTokenRepo->introspect($tokenValue);
        self::assertNull($introspected);
    }

    #[Test]
    public function expiredRefreshTokenCannotBeConsumed(): void
    {
        $tokenValue = bin2hex(random_bytes(32));
        $refreshToken = new RefreshToken(
            id: 'rt-expired-001',
            clientId: 'client-a',
            subjectId: 'user-42',
            sessionId: 'session-1',
            familyId: 'family-expired',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-31 days'),
            tokenValue: $tokenValue,
        );
        $this->refreshTokenRepo->persist($refreshToken);

        $consumed = $this->refreshTokenRepo->consume($tokenValue);
        self::assertNull($consumed);
    }

    #[Test]
    public function expiredAuthorizationCodeCannotBeConsumed(): void
    {
        $codeValue = bin2hex(random_bytes(32));
        $code = new AuthorizationCode(
            id: 'ac-expired-001',
            clientId: 'client-a',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-11 minutes'),
            codeValue: $codeValue,
        );
        $this->codeRepo->persist($code);

        $consumed = $this->codeRepo->consume($codeValue);
        self::assertNull($consumed);
    }

    // ========================================================================
    // Attack Vector 10: Token Hash Storage Verification
    // ========================================================================

    #[Test]
    public function tokenLookupRequiresOriginalValueNotHash(): void
    {
        $tokenValue = bin2hex(random_bytes(32));
        $accessToken = new AccessToken(
            id: 'at-hash-001',
            clientId: 'client-a',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->accessTokenRepo->persist($accessToken);

        // Looking up by the hash of the token value should fail
        $hash = hash('sha256', $tokenValue);
        $introspected = $this->accessTokenRepo->introspect($hash);
        self::assertNull($introspected);

        // Only the original value works
        $introspected = $this->accessTokenRepo->introspect($tokenValue);
        self::assertNotNull($introspected);
    }

    // ========================================================================
    // Attack Vector 11: Subject-Wide Revocation
    // ========================================================================

    #[Test]
    public function subjectRevocationInvalidatesAllUserTokens(): void
    {
        $token1Value = bin2hex(random_bytes(32));
        $token2Value = bin2hex(random_bytes(32));

        $this->accessTokenRepo->persist(new AccessToken(
            id: 'at-sub-001',
            clientId: 'client-a',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $token1Value,
        ));

        $this->accessTokenRepo->persist(new AccessToken(
            id: 'at-sub-002',
            clientId: 'client-b',
            subjectId: 'user-42',
            scopes: ['api:read'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $token2Value,
        ));

        // Revoke all tokens for user-42
        $this->accessTokenRepo->revokeBySubject('user-42');

        self::assertNull($this->accessTokenRepo->introspect($token1Value));
        self::assertNull($this->accessTokenRepo->introspect($token2Value));
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createConfidentialClient(string $id): OAuthClient
    {
        return new OAuthClient(
            id: $id,
            name: "Test Client {$id}",
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code', 'refresh_token', 'client_credentials'],
            scopes: ['openid', 'api:read'],
            confidential: true,
            secretHash: '$2y$10$fakehash',
        );
    }

    private function computeS256Challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function createAndPersistRefreshToken(string $clientId, string $subjectId, string $tokenValue): RefreshToken
    {
        $token = new RefreshToken(
            id: bin2hex(random_bytes(16)),
            clientId: $clientId,
            subjectId: $subjectId,
            sessionId: 'session-1',
            familyId: bin2hex(random_bytes(16)),
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->refreshTokenRepo->persist($token);

        return $token;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createTokenRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }

    private function createScopeRepository(): ScopeRepositoryInterface
    {
        $repo = $this->createMock(ScopeRepositoryInterface::class);
        $repo->method('resolveScopes')->willReturnCallback(
            static function (array $scopeIds): array {
                /** @var list<string> $scopeIds */
                return array_map(static fn(string $id) => new Scope($id), $scopeIds);
            },
        );

        return $repo;
    }
}
