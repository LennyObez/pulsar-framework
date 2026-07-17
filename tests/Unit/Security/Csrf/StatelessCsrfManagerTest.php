<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Csrf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Csrf\StatelessCsrfManager;
use RuntimeException;

use function bin2hex;
use function pack;
use function sodium_crypto_auth;
use function sodium_crypto_auth_keygen;
use function strlen;
use function time;

#[CoversClass(StatelessCsrfManager::class)]
final class StatelessCsrfManagerTest extends TestCase
{
    private string $key;
    private StatelessCsrfManager $manager;

    protected function setUp(): void
    {
        $this->key = sodium_crypto_auth_keygen();
        $this->manager = new StatelessCsrfManager($this->key);
    }

    public function testGenerateProducesHexToken(): void
    {
        $token = $this->manager->generate();

        self::assertNotEmpty($token);
        // Masked, hex-encoded: pad (40 bytes) + (inner XOR pad) (40 bytes) =
        // 80 bytes = 160 hex chars. The inner token is timestamp (8) + MAC (32).
        self::assertSame(160, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    public function testEachGeneratedTokenIsDistinctSoItIsNotAStableBreachTarget(): void
    {
        // Same second, same action, yet the transmitted values differ because
        // of the per-response one-time-pad mask — no stable compression oracle.
        $a = $this->manager->generate();
        $b = $this->manager->generate();

        self::assertNotSame($a, $b);
        self::assertTrue($this->manager->validate($a));
        self::assertTrue($this->manager->validate($b));
    }

    public function testValidateAcceptsTheLegacyUnmaskedTokenFormatDuringRollover(): void
    {
        // A token minted by the pre-masking version (inner token, hex, 80
        // chars) must still validate so an in-flight form survives a deploy.
        $timestampBytes = pack('J', time());
        $mac = sodium_crypto_auth($timestampBytes . '_default', $this->key);
        $legacy = bin2hex($timestampBytes . $mac);

        self::assertSame(80, strlen($legacy));
        self::assertTrue($this->manager->validate($legacy));
    }

    public function testValidateAcceptsValidToken(): void
    {
        $token = $this->manager->generate();

        self::assertTrue($this->manager->validate($token));
    }

    public function testValidateRejectsTamperedToken(): void
    {
        $token = $this->manager->generate();
        $tampered = $token;
        $tampered[10] = $tampered[10] === 'a' ? 'b' : 'a';

        self::assertFalse($this->manager->validate($tampered));
    }

    public function testValidateRejectsEmptyString(): void
    {
        self::assertFalse($this->manager->validate(''));
    }

    public function testValidateRejectsNonHexString(): void
    {
        self::assertFalse($this->manager->validate('not-a-hex-token'));
    }

    public function testValidateRejectsWrongLengthToken(): void
    {
        self::assertFalse($this->manager->validate('abcdef'));
    }

    public function testValidateRejectsExpiredToken(): void
    {
        // Create a manager with 1-second window
        $manager = new StatelessCsrfManager($this->key, windowSeconds: 0);

        $token = $manager->generate();

        // The token was just generated at time(), window is 0 seconds
        // So it should be valid now but let's test the boundary
        // Token at time() with window 0 means (now - tokenTime) <= 0
        // Since tokenTime == now, (now - now) = 0 <= 0, still valid.
        // We need an actually expired token — manually craft one
        $oldTimestamp = time() - 10;
        $timestampBytes = pack('J', $oldTimestamp);
        $mac = sodium_crypto_auth($timestampBytes . '_default', $this->key);
        $expiredToken = bin2hex($timestampBytes . $mac);

        self::assertFalse($manager->validate($expiredToken));
    }

    public function testActionBindingPreventsReuse(): void
    {
        $token = $this->manager->generateForAction('create_post');

        self::assertTrue($this->manager->validateForAction($token, 'create_post'));
        self::assertFalse($this->manager->validateForAction($token, 'delete_post'));
    }

    public function testDifferentActionsProduceDifferentTokens(): void
    {
        $token1 = $this->manager->generateForAction('action_a');
        $token2 = $this->manager->generateForAction('action_b');

        self::assertNotSame($token1, $token2);
    }

    public function testDifferentKeysRejectEachOther(): void
    {
        $otherKey = sodium_crypto_auth_keygen();
        $otherManager = new StatelessCsrfManager($otherKey);

        $token = $this->manager->generate();

        self::assertFalse($otherManager->validate($token));
    }

    public function testGetTokenGeneratesNew(): void
    {
        $token = $this->manager->getToken();

        self::assertNotEmpty($token);
        self::assertTrue($this->manager->validate($token));
    }

    public function testRotateGeneratesNew(): void
    {
        $token = $this->manager->rotate();

        self::assertNotEmpty($token);
        self::assertTrue($this->manager->validate($token));
    }

    public function testValidateRejectsFutureTimestamp(): void
    {
        $futureTimestamp = time() + 3600;
        $timestampBytes = pack('J', $futureTimestamp);
        $mac = sodium_crypto_auth($timestampBytes . '_default', $this->key);
        $futureToken = bin2hex($timestampBytes . $mac);

        self::assertFalse($this->manager->validate($futureToken));
    }

    public function testDebugInfoRedactsKey(): void
    {
        $debug = $this->manager->__debugInfo();

        self::assertSame('[REDACTED]', $debug['secretKey']);
        self::assertSame(600, $debug['windowSeconds']);
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

    public function testCustomWindowSeconds(): void
    {
        $manager = new StatelessCsrfManager($this->key, windowSeconds: 7200);
        $token = $manager->generate();

        self::assertTrue($manager->validate($token));
    }

    public function testCustomDefaultAction(): void
    {
        $manager = new StatelessCsrfManager($this->key, defaultAction: 'my_form');
        $token = $manager->generate();

        // Should validate as 'my_form' action
        self::assertTrue($manager->validateForAction($token, 'my_form'));
        // Should NOT validate as default '_default' action
        self::assertFalse($manager->validateForAction($token, '_default'));
    }

    public function testDefaultWindowIs600Seconds(): void
    {
        // The default window should be 600s (10 minutes), not 3600s (1 hour)
        $manager = new StatelessCsrfManager($this->key);
        $debug = $manager->__debugInfo();

        self::assertSame(600, $debug['windowSeconds']);
    }

    public function testTokenExpiredAfter600SecondsDefault(): void
    {
        // Create a token with timestamp 601 seconds in the past
        $oldTimestamp = time() - 601;
        $timestampBytes = pack('J', $oldTimestamp);
        $mac = sodium_crypto_auth($timestampBytes . '_default', $this->key);
        $expiredToken = bin2hex($timestampBytes . $mac);

        // Default window is 600s, so 601s-old token must be rejected
        self::assertFalse($this->manager->validate($expiredToken));
    }

    public function testTokenValidWithin600SecondsDefault(): void
    {
        // Create a token with timestamp 599 seconds in the past
        $recentTimestamp = time() - 599;
        $timestampBytes = pack('J', $recentTimestamp);
        $mac = sodium_crypto_auth($timestampBytes . '_default', $this->key);
        $recentToken = bin2hex($timestampBytes . $mac);

        // Default window is 600s, so 599s-old token must be accepted
        self::assertTrue($this->manager->validate($recentToken));
    }
}
