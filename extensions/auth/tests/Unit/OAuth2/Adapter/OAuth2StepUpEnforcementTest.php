<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Adapter;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\LevelOfAssurance;
use Pulsar\Auth\Middleware\LevelOfAssuranceMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Extension\Auth\OAuth2\Adapter\OAuth2TokenResolver;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

/**
 * Regression suite — verifies that an OAuth2 access token neither
 * bypasses nor is wrongly blocked by step-up / level-of-assurance
 * enforcement.
 *
 * The relying party can only honour the IdP's MFA assertion if
 * OAuth2TokenResolver derives TwoFactorStatus from the standard OIDC
 * `amr` / `acr` claims. A resolver that reported a fixed status would
 * either deny legitimate MFA-asserted access, or treat an MFA assertion
 * as if it had never been made on routes that do not enforce LoA.
 *
 * This test exercises the end-to-end path: token introspection ->
 * claims provider -> resolver -> LevelOfAssuranceMiddleware decision.
 */
#[CoversClass(OAuth2TokenResolver::class)]
#[CoversClass(LevelOfAssuranceMiddleware::class)]
final class OAuth2StepUpEnforcementTest extends TestCase
{
    #[Test]
    public function tokenWithMfaAmrPassesSubstantialLoaCheck(): void
    {
        // IdP asserted MFA via amr: ["pwd", "mfa"] in the access token's
        // claims -> resolver maps to TwoFactorStatus::Verified ->
        // LevelOfAssuranceMiddleware computes Substantial -> request
        // satisfying `requiredLevel: Substantial` is allowed through.
        $response = $this->runRequestThroughLoaMiddleware(
            claimsForToken: ['name' => 'Banker', 'amr' => ['pwd', 'mfa']],
            requiredLevel: LevelOfAssurance::Substantial,
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function tokenWithoutMfaAssertionIsRefusedSubstantialLoaCheck(): void
    {
        // IdP issued a single-factor token (no `amr`, no MFA-grade `acr`).
        // OAuth2TokenResolver maps to TwoFactorStatus::Disabled — even if
        // the underlying user has 2FA enabled in the local user store,
        // the OAuth2 grant does not carry that assertion. The RP's
        // step-up route refuses the token instead of silently bypassing
        // the 2FA requirement.
        $response = $this->runRequestThroughLoaMiddleware(
            claimsForToken: ['name' => 'Single Factor User'],
            requiredLevel: LevelOfAssurance::Substantial,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function tokenWithPasswordOnlyAmrIsRefusedSubstantialLoaCheck(): void
    {
        // amr: ["pwd"] alone is single-factor — must NOT satisfy
        // Substantial. The relying party explicitly refuses to treat
        // password-only as MFA regardless of what the IdP claims about
        // its own authentication strength.
        $response = $this->runRequestThroughLoaMiddleware(
            claimsForToken: ['amr' => ['pwd']],
            requiredLevel: LevelOfAssurance::Substantial,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function tokenWithAcrLevel2PassesSubstantialLoaCheck(): void
    {
        $response = $this->runRequestThroughLoaMiddleware(
            claimsForToken: ['acr' => '2'],
            requiredLevel: LevelOfAssurance::Substantial,
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function tokenWithIncommonSilverAcrPassesSubstantialLoaCheck(): void
    {
        $response = $this->runRequestThroughLoaMiddleware(
            claimsForToken: ['acr' => 'urn:mace:incommon:iap:silver'],
            requiredLevel: LevelOfAssurance::Substantial,
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function tokenIsRefusedHighLoaWithoutHardwareAttestation(): void
    {
        // High LoA requires either an explicit `_loa_high` request
        // attribute (hardware token guard) or — currently — is not
        // reachable from amr/acr alone. Confirm the conservative
        // mapping: even amr:[mfa] does not promote the request to
        // High; only Substantial.
        $response = $this->runRequestThroughLoaMiddleware(
            claimsForToken: ['amr' => ['mfa', 'hwk']],
            requiredLevel: LevelOfAssurance::High,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $claimsForToken
     */
    private function runRequestThroughLoaMiddleware(
        array $claimsForToken,
        LevelOfAssurance $requiredLevel,
    ): ResponseInterface {
        $token = new AccessToken(
            id: 'tok-loa',
            clientId: 'banking-client',
            subjectId: 'user-loa',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        $tokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $tokenRepo->method('introspect')->willReturn($token);

        $claimsProvider = $this->createStub(UserClaimsProviderInterface::class);
        $claimsProvider->method('getClaims')->willReturn($claimsForToken);

        $resolver = new OAuth2TokenResolver($tokenRepo, $claimsProvider);
        $identity = $resolver->resolve('bearer-token');
        self::assertNotNull($identity);

        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        $request = new ServerRequest(method: 'POST', uri: '/banking/wire-transfer');
        $securityContext = new SecurityContext($authManager, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $middleware = new LevelOfAssuranceMiddleware($requiredLevel);
        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(statusCode: ResponseStatus::OK->value, body: 'wire-transfer-accepted');
            }
        };

        return $middleware->process($request, $handler);
    }
}
