<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Csrf;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Csrf\CsrfReplayGuardInterface;
use Pulsar\Security\Csrf\EncryptedCsrfManager;
use Pulsar\Security\Session\SessionInterface;
use RuntimeException;

use function bin2hex;
use function sodium_crypto_kdf_keygen;

#[CoversClass(EncryptedCsrfManager::class)]
final class EncryptedCsrfManagerTest extends TestCase
{
    private Encryptor $encryptor;
    private EncryptedCsrfManager $manager;

    protected function setUp(): void
    {
        $hex = bin2hex(sodium_crypto_kdf_keygen());
        $masterKey = MasterKey::fromHex($hex);
        $this->encryptor = Encryptor::fromMasterKey($masterKey);
        $this->manager = new EncryptedCsrfManager(
            $this->encryptor,
            $this->session('session_abc123'),
        );
    }

    /**
     * A session stub with a fixed id (mutable so regenerate scenarios can be
     * modelled), standing in for the real SessionManager.
     */
    private function session(string $id): SessionInterface
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('id')->willReturn($id);

        return $session;
    }

    public function testGenerateProducesNonEmptyToken(): void
    {
        self::assertNotEmpty($this->manager->generate());
    }

    public function testValidateAcceptsValidToken(): void
    {
        $token = $this->manager->generate();

        self::assertTrue($this->manager->validate($token));
    }

    public function testValidateRejectsTamperedToken(): void
    {
        $token = $this->manager->generate();

        self::assertFalse($this->manager->validate($token . 'x'));
    }

    public function testValidateRejectsEmptyString(): void
    {
        self::assertFalse($this->manager->validate(''));
    }

    public function testValidateRejectsGarbageString(): void
    {
        self::assertFalse($this->manager->validate('not-a-valid-encrypted-token'));
    }

    public function testSessionBindingPreventsReuse(): void
    {
        $token = $this->manager->generate();

        self::assertTrue($this->manager->validate($token));

        $otherManager = new EncryptedCsrfManager($this->encryptor, $this->session('different_session'));

        self::assertFalse($otherManager->validate($token));
    }

    #[Test]
    public function theSessionIdIsReadFreshSoRegenerationInvalidatesOldTokens(): void
    {
        // The trap this pins: a manager that froze the session id at construction
        // would keep validating tokens against the pre-regeneration id. Read
        // fresh, a token minted under the old id must fail once the id changes.
        $session = $this->createStub(SessionInterface::class);
        $session->method('id')->willReturnOnConsecutiveCalls(
            'old_session', // generate() binds to this
            'new_session', // validate() reads this — the session regenerated
        );

        $manager = new EncryptedCsrfManager($this->encryptor, $session);

        $token = $manager->generate();

        self::assertFalse($manager->validate($token), 'A token bound to the old session id must not validate under the new one');
    }

    #[Test]
    public function operationsFailClosedWhenNoSessionIsActive(): void
    {
        $manager = new EncryptedCsrfManager($this->encryptor, $this->session(''));

        $this->expectException(LogicException::class);

        $manager->generate();
    }

    public function testActionBindingPreventsReuse(): void
    {
        $token = $this->manager->generateForAction('create_post');

        self::assertTrue($this->manager->validateForAction($token, 'create_post'));
        self::assertFalse($this->manager->validateForAction($token, 'delete_post'));
    }

    public function testEachTokenIsUnique(): void
    {
        self::assertNotSame($this->manager->generate(), $this->manager->generate());
    }

    #[Test]
    public function theWindowIsEnforcedAgainstAnInjectedClock(): void
    {
        // Real expiry coverage, which the old test could not provide: mint at
        // t=1000 with a 60s window, validate at t=1061 — one second past.
        $now = 1000;
        $manager = new EncryptedCsrfManager(
            $this->encryptor,
            $this->session('session_abc123'),
            windowSeconds: 60,
            clock: static function () use (&$now): int {
                return $now;
            },
        );

        $token = $manager->generate();

        $now = 1060; // exactly at the edge — still valid
        self::assertTrue($manager->validate($token));

        $now = 1061; // one second past the window
        self::assertFalse($manager->validate($token));
    }

    #[Test]
    public function aFutureDatedTokenIsRejected(): void
    {
        $now = 2000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $manager = new EncryptedCsrfManager($this->encryptor, $this->session('s'), clock: $clock);

        $token = $manager->generate();

        $now = 1000; // clock went backwards — token is "from the future"
        self::assertFalse($manager->validate($token));
    }

    #[Test]
    public function aWiredReplayGuardMakesTokensSingleUse(): void
    {
        $guard = new class implements CsrfReplayGuardInterface {
            /** @var array<string, true> */
            private array $seen = [];

            public function consume(string $nonce, int $ttlSeconds): bool
            {
                if (isset($this->seen[$nonce])) {
                    return false;
                }
                $this->seen[$nonce] = true;

                return true;
            }
        };

        $manager = new EncryptedCsrfManager($this->encryptor, $this->session('s'), replayGuard: $guard);

        $token = $manager->generate();

        self::assertTrue($manager->validate($token), 'first use accepted');
        self::assertFalse($manager->validate($token), 'replay rejected');
    }

    #[Test]
    public function withoutAReplayGuardTokensAreReplayableWithinTheWindow(): void
    {
        $token = $this->manager->generate();

        self::assertTrue($this->manager->validate($token));
        self::assertTrue($this->manager->validate($token), 'stateless tokens replay within their window by design');
    }

    public function testDifferentKeysCannotDecrypt(): void
    {
        $otherHex = bin2hex(sodium_crypto_kdf_keygen());
        $otherEncryptor = Encryptor::fromMasterKey(MasterKey::fromHex($otherHex));
        $otherManager = new EncryptedCsrfManager($otherEncryptor, $this->session('session_abc123'));

        $token = $this->manager->generate();

        self::assertFalse($otherManager->validate($token));
    }

    public function testGetTokenGeneratesNew(): void
    {
        $token = $this->manager->getToken();

        self::assertNotEmpty($token);
        self::assertTrue($this->manager->validate($token));
    }

    #[Test]
    public function rotateRegeneratesTheSessionAndIssuesAFreshToken(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('id')->willReturn('session_abc123');
        $session->expects(self::once())->method('regenerate')->with(self::isTrue());

        $manager = new EncryptedCsrfManager($this->encryptor, $session);

        $token = $manager->rotate();

        self::assertNotEmpty($token);
        self::assertTrue($manager->validate($token));
    }

    public function testDebugInfoRedactsSensitiveData(): void
    {
        $debug = $this->manager->__debugInfo();

        self::assertSame('[REDACTED]', $debug['sessionId']);
        self::assertSame('[ENCRYPTOR]', $debug['encryptor']);
        self::assertSame(3600, $debug['windowSeconds']);
    }

    public function testSerializationThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager->__serialize();
    }

    public function testUnserializationThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager->__unserialize([]);
    }

    public function testCustomWindowAndDefaultAction(): void
    {
        $manager = new EncryptedCsrfManager(
            $this->encryptor,
            $this->session('session_abc123'),
            windowSeconds: 7200,
            defaultAction: 'my_form',
        );

        $token = $manager->generate();

        self::assertTrue($manager->validateForAction($token, 'my_form'));
        self::assertFalse($manager->validateForAction($token, '_default'));
    }

    public function testMultipleActionsAreIndependent(): void
    {
        $tokenA = $this->manager->generateForAction('action_a');
        $tokenB = $this->manager->generateForAction('action_b');

        self::assertTrue($this->manager->validateForAction($tokenA, 'action_a'));
        self::assertTrue($this->manager->validateForAction($tokenB, 'action_b'));
        self::assertFalse($this->manager->validateForAction($tokenA, 'action_b'));
        self::assertFalse($this->manager->validateForAction($tokenB, 'action_a'));
    }
}
