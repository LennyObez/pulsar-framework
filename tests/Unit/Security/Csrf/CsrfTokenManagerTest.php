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

        // 32 random bytes = 64 hex chars
        self::assertSame(64, strlen($token));
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

        self::assertSame(64, strlen($token));
    }

    #[Test]
    public function getTokenReturnsExistingToken(): void
    {
        $existingToken = bin2hex(random_bytes(32));
        $session = $this->createSessionMock($existingToken);
        $csrfConfig = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $manager = new CsrfTokenManager($session, $csrfConfig);

        self::assertSame($existingToken, $manager->getToken());
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

        self::assertSame(64, strlen($newToken));
        // The new token should differ from the old (statistically guaranteed)
        self::assertNotSame($oldToken, $newToken);
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
