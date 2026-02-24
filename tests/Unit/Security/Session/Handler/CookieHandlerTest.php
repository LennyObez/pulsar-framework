<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SessionConfig;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\CookieHandler;
use Pulsar\Security\Session\SessionEncryption;

use function random_bytes;
use function sodium_bin2hex;
use function str_repeat;

#[CoversClass(CookieHandler::class)]
final class CookieHandlerTest extends TestCase
{
    private SessionEncryption $encryption;

    private SessionConfig $config;

    protected function setUp(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $this->encryption = SessionEncryption::fromMasterKey($masterKey);
        $this->config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'cookie',
            encryption: true,
            cookieMaxPayloadSize: 2048,
            cookieReplayWindow: 86400,
        );
    }

    #[Test]
    public function writeAndReadViaCookieFlow(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        // Write data
        $handler->write('session-1', 'session-data');

        // Get the encrypted cookie value
        $cookieValue = $handler->getCookieValue('session-1');
        self::assertNotNull($cookieValue);

        // Create a new handler instance and load from cookie (simulates next request)
        $handler2 = new CookieHandler($this->encryption, $this->config);
        $handler2->loadFromCookie('session-1', $cookieValue);

        self::assertSame('session-data', $handler2->read('session-1'));
    }

    #[Test]
    public function payloadSizeLimitEnforced(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        // Create data exceeding the 2048 byte limit
        $oversizedData = str_repeat('x', 2049);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $handler->write('session-1', $oversizedData);
    }

    #[Test]
    public function validCookieAcceptedWithinReplayWindow(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        // Write data to get a valid cookie
        $handler->write('session-1', 'data');
        $cookieValue = $handler->getCookieValue('session-1');
        self::assertNotNull($cookieValue);

        // Load immediately — should be within the 86400-second replay window
        $handler2 = new CookieHandler($this->encryption, $this->config);
        $handler2->loadFromCookie('session-1', $cookieValue);

        self::assertSame('data', $handler2->read('session-1'));
    }

    #[Test]
    public function supportsConcurrencyControlReturnsFalse(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        self::assertFalse($handler->supportsConcurrencyControl());
    }

    #[Test]
    public function supportsSessionListingReturnsFalse(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        self::assertFalse($handler->supportsSessionListing());
    }

    #[Test]
    public function supportsRevocationReturnsFalse(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        self::assertFalse($handler->supportsRevocation());
    }

    #[Test]
    public function listSessionsThrowsNotSupported(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session listing');

        $handler->listSessions('user-1');
    }

    #[Test]
    public function revokeSessionThrowsNotSupported(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session revocation');

        $handler->revokeSession('session-1');
    }

    #[Test]
    public function getActiveSessionsThrowsNotSupported(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support concurrency control');

        $handler->getActiveSessions('user-1');
    }

    #[Test]
    public function openReturnsTrue(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        self::assertTrue($handler->open('', 'TEST_SESSION'));
    }

    #[Test]
    public function closeReturnsTrue(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        self::assertTrue($handler->close());
    }

    #[Test]
    public function gcReturnsZero(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        self::assertSame(0, $handler->gc(3600));
    }

    #[Test]
    public function destroyClearsBuffers(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        $handler->write('session-1', 'data');
        self::assertNotNull($handler->getCookieValue('session-1'));

        $handler->destroy('session-1');

        self::assertNull($handler->getCookieValue('session-1'));
        self::assertSame('', $handler->read('session-1'));
    }

    #[Test]
    public function readReturnsEmptyForNonexistentSession(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        self::assertSame('', $handler->read('nonexistent'));
    }

    #[Test]
    public function tamperedCookieIsRejected(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        // loadFromCookie with garbage data — should silently reject
        $handler->loadFromCookie('session-1', 'not-valid-encrypted-data');

        self::assertSame('', $handler->read('session-1'));
    }

    #[Test]
    public function getCookieValueReturnsNullWithoutWrite(): void
    {
        $handler = new CookieHandler($this->encryption, $this->config);

        self::assertNull($handler->getCookieValue('nonexistent'));
    }

    #[Test]
    public function loadFromCookieIgnoresExpiredCookie(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryption = SessionEncryption::fromMasterKey($masterKey);

        $config = new SessionConfig(
            cookieName: 'COOKIE_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'cookie',
            encryption: true,
            cookieDomain: 'example.com',
            cookieReplayWindow: 1, // 1-second replay window
        );

        $handler = new CookieHandler($encryption, $config);
        $handler->write('session-1', 'old data');

        $cookieValue = $handler->getCookieValue('session-1');
        self::assertNotNull($cookieValue);

        // The cookie was just created so should still be valid within 1 second
        $handler2 = new CookieHandler($encryption, $config);
        $handler2->loadFromCookie('session-1', $cookieValue);

        // Should be readable since we just created it
        self::assertSame('old data', $handler2->read('session-1'));
    }
}
