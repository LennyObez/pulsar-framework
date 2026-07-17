<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Csrf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CsrfConfig;
use Pulsar\Security\Csrf\CsrfTokenManager;
use Pulsar\Security\Session\SessionInterface;

use function strlen;

#[CoversClass(CsrfTokenManager::class)]
final class CsrfTokenManagerTest extends TestCase
{
    protected function setUp(): void {}


    #[Test]
    public function generateProducesTokenOfCorrectLength(): void
    {
        // We need to mock session since we can't start a real session in PHPUnit
        $session = $this->createSessionMock();
        $csrfConfig = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $manager = new CsrfTokenManager($session, $csrfConfig);
        $token = $manager->generate();

        // 32 random bytes = 64 hex chars stored, emitted masked as
        // hex(pad || inner XOR pad) = twice that.
        self::assertSame(128, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    #[Test]
    public function getTokenGeneratesIfNoneExists(): void
    {
        $session = $this->createSessionMock(null);
        $csrfConfig = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $manager = new CsrfTokenManager($session, $csrfConfig);
        $token = $manager->getToken();

        self::assertSame(128, strlen($token));
    }

    #[Test]
    public function getTokenMasksTheExistingTokenRatherThanEmittingItVerbatim(): void
    {
        $existingToken = bin2hex(random_bytes(32));
        $session = $this->createSessionMock($existingToken);
        $manager = new CsrfTokenManager($session, $this->csrfConfig());

        $emitted = $manager->getToken();

        // The stored secret must never appear on the wire as-is.
        self::assertNotSame($existingToken, $emitted);
        self::assertStringNotContainsString($existingToken, $emitted);
        // ...but it must still be the token being carried.
        self::assertTrue($manager->validate($emitted));
    }

    /**
     * The BREACH regression, stated as the reporter observed it: the token was
     * byte-identical across three responses of one session. With the page
     * served Content-Encoding: br, a stable secret in every response is exactly
     * the oracle target — an attacker who can influence any reflected byte
     * recovers it by watching compressed response sizes. Each render must now
     * emit different bytes for the same underlying token.
     */
    #[Test]
    public function getTokenEmitsDifferentBytesOnEveryRenderOfOneSession(): void
    {
        $session = $this->createSessionMock(bin2hex(random_bytes(32)));
        $manager = new CsrfTokenManager($session, $this->csrfConfig());

        $first = $manager->getToken();
        $second = $manager->getToken();
        $third = $manager->getToken();

        self::assertCount(3, array_unique([$first, $second, $third]), 'Each render must be a fresh mask');

        // All three must nonetheless validate: they carry the same secret.
        self::assertTrue($manager->validate($first));
        self::assertTrue($manager->validate($second));
        self::assertTrue($manager->validate($third));
    }

    /**
     * A token rendered into a page before this deploy is still in flight when
     * the new code starts validating. Rejecting it would log every open tab out
     * of its form on deploy.
     */
    #[Test]
    public function validateAcceptsTheLegacyVerbatimTokenDuringRollover(): void
    {
        $legacy = bin2hex(random_bytes(32));
        $session = $this->createSessionMock($legacy);
        $manager = new CsrfTokenManager($session, $this->csrfConfig());

        self::assertTrue($manager->validate($legacy));
    }

    #[Test]
    public function validateRejectsAMaskedTokenCarryingTheWrongSecret(): void
    {
        $session = $this->createSessionMock(bin2hex(random_bytes(32)));
        $manager = new CsrfTokenManager($session, $this->csrfConfig());

        // A well-formed mask of a DIFFERENT secret must not pass: masking is
        // an encoding, not an authenticator.
        $otherSecret = random_bytes(32);
        $pad = random_bytes(32);
        $forged = bin2hex($pad . ($otherSecret ^ $pad));

        self::assertSame(128, strlen($forged), 'guard: the forgery must be well-formed');
        self::assertFalse($manager->validate($forged));
    }

    private function csrfConfig(): CsrfConfig
    {
        return new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );
    }

    #[Test]
    public function validateReturnsTrueForMatchingToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $session = $this->createSessionMock($token);
        $csrfConfig = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $manager = new CsrfTokenManager($session, $csrfConfig);

        self::assertTrue($manager->validate($token));
    }

    #[Test]
    public function validateReturnsFalseForMismatchedToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $session = $this->createSessionMock($token);
        $csrfConfig = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $manager = new CsrfTokenManager($session, $csrfConfig);

        self::assertFalse($manager->validate('wrong_token'));
    }

    #[Test]
    public function validateReturnsFalseWhenNoTokenInSession(): void
    {
        $session = $this->createSessionMock(null);
        $csrfConfig = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $manager = new CsrfTokenManager($session, $csrfConfig);

        self::assertFalse($manager->validate('any_token'));
    }

    #[Test]
    public function rotateGeneratesNewToken(): void
    {
        $oldToken = bin2hex(random_bytes(32));
        $session = $this->createSessionMock($oldToken);
        $csrfConfig = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $manager = new CsrfTokenManager($session, $csrfConfig);
        $newToken = $manager->rotate();

        self::assertSame(128, strlen($newToken), 'rotate() emits a masked token like generate()');
        // The new token should differ from the old (statistically guaranteed)
        self::assertNotSame($oldToken, $newToken);
    }

    /**
     * F9.6: rotate() must regenerate the underlying session id (with the
     * old session destroyed) so any session-fixation attempt is swept
     * along with the old token. A mock-style assertion on the regenerate
     * call confirms the contract.
     */
    #[Test]
    public function rotateRegeneratesSessionIdDeletingOldSession(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('get')->willReturn(null);
        $session->expects(self::once())
            ->method('regenerate')
            ->with(self::isTrue());

        $csrfConfig = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $manager = new CsrfTokenManager($session, $csrfConfig);
        $manager->rotate();
    }

    /**
     * Create a mock SessionInterface that returns the given token for the CSRF session key.
     */
    private function createSessionMock(?string $storedToken = 'default'): SessionInterface
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);

        if ($storedToken === null) {
            $session->method('get')
                ->willReturn(null);
        } else {
            $session->method('get')
                ->willReturn($storedToken === 'default' ? null : $storedToken);
        }

        return $session;
    }
}
