<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Csrf;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Csrf\StatelessCsrfManager;
use RuntimeException;

use function bin2hex;
use function pack;
use function sodium_crypto_auth;
use function sodium_crypto_auth_keygen;
use function str_repeat;
use function strlen;
use function time;

#[CoversClass(StatelessCsrfManager::class)]
final class StatelessCsrfManagerTest extends TestCase
{
    private const string BINDING = 'browser-binding-secret-value';

    private string $key;
    private StatelessCsrfManager $manager;

    protected function setUp(): void
    {
        $this->key = sodium_crypto_auth_keygen();
        $this->manager = $this->managerWithBinding(self::BINDING);
    }

    private function managerWithBinding(string $binding, ?string $key = null): StatelessCsrfManager
    {
        return new StatelessCsrfManager($key ?? $this->key, static fn(): string => $binding);
    }

    /**
     * Hand-craft an inner token (unmasked, hex) for the given action/binding —
     * mirrors the manager's MAC message: timestamp || len(action) || action ||
     * binding.
     */
    private function craftLegacyStyleToken(int $timestamp, string $action, string $binding): string
    {
        $timestampBytes = pack('J', $timestamp);
        $message = $timestampBytes . pack('J', strlen($action)) . $action . $binding;
        $mac = sodium_crypto_auth($message, $this->key);

        return bin2hex($timestampBytes . $mac);
    }

    public function testGenerateProducesHexToken(): void
    {
        $token = $this->manager->generate();

        self::assertNotEmpty($token);
        // Masked, hex: pad (40 bytes) + (inner XOR pad) (40 bytes) = 80 bytes =
        // 160 hex chars. Inner = timestamp (8) + MAC (32).
        self::assertSame(160, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    public function testEachGeneratedTokenIsDistinctSoItIsNotAStableBreachTarget(): void
    {
        $a = $this->manager->generate();
        $b = $this->manager->generate();

        self::assertNotSame($a, $b);
        self::assertTrue($this->manager->validate($a));
        self::assertTrue($this->manager->validate($b));
    }

    #[Test]
    public function aTokenBoundToOneBrowserIsRejectedForAnother(): void
    {
        // The #425 fix: the MAC binds a per-browser secret, so a token an
        // attacker mints in their own browser (their binding) is worthless when
        // submitted from the victim's browser (a different binding).
        $attacker = $this->managerWithBinding('attacker-cookie-value');
        $victim = $this->managerWithBinding('victim-cookie-value');

        $attackerToken = $attacker->generate();

        self::assertTrue($attacker->validate($attackerToken), 'valid in its own browser');
        self::assertFalse($victim->validate($attackerToken), 'worthless in the victim browser');
    }

    #[Test]
    public function generateFailsClosedWhenNoBindingIsPresent(): void
    {
        $manager = $this->managerWithBinding('');

        $this->expectException(LogicException::class);

        $manager->generate();
    }

    #[Test]
    public function validateFailsClosedWhenNoBindingIsPresent(): void
    {
        $token = $this->manager->generate();
        $unbound = $this->managerWithBinding('');

        $this->expectException(LogicException::class);

        $unbound->validate($token);
    }

    #[Test]
    public function aSecretKeyOfTheWrongLengthIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StatelessCsrfManager('too-short', static fn(): string => self::BINDING);
    }

    public function testValidateAcceptsValidToken(): void
    {
        self::assertTrue($this->manager->validate($this->manager->generate()));
    }

    public function testValidateRejectsTamperedToken(): void
    {
        $token = $this->manager->generate();
        $token[10] = $token[10] === 'a' ? 'b' : 'a';

        self::assertFalse($this->manager->validate($token));
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

    #[Test]
    public function theLegacyVerbatimTokenFormatIsNoLongerAccepted(): void
    {
        // Only the masked (2x) form is valid now; the pre-masking 1x-length
        // layout could not carry a binding, so there are no in-flight tokens of
        // it to honor.
        $legacyOneX = $this->craftLegacyStyleToken(time(), '_default', self::BINDING);

        self::assertSame(80, strlen($legacyOneX));
        self::assertFalse($this->manager->validate($legacyOneX));
    }

    public function testValidateRejectsExpiredToken(): void
    {
        $manager = new StatelessCsrfManager($this->key, static fn(): string => self::BINDING, windowSeconds: 0);
        $expired = $this->craftMaskedToken(time() - 10, '_default', self::BINDING);

        self::assertFalse($manager->validate($expired));
    }

    /**
     * Craft a masked token (the transmitted 2x-length form) for expiry tests.
     */
    private function craftMaskedToken(int $timestamp, string $action, string $binding): string
    {
        $timestampBytes = pack('J', $timestamp);
        $message = $timestampBytes . pack('J', strlen($action)) . $action . $binding;
        $mac = sodium_crypto_auth($message, $this->key);
        $inner = $timestampBytes . $mac;
        $pad = str_repeat("\0", strlen($inner)); // zero pad → masked == inner, still 2x length

        return bin2hex($pad . ($inner ^ $pad));
    }

    public function testActionBindingPreventsReuse(): void
    {
        $token = $this->manager->generateForAction('create_post');

        self::assertTrue($this->manager->validateForAction($token, 'create_post'));
        self::assertFalse($this->manager->validateForAction($token, 'delete_post'));
    }

    public function testDifferentActionsProduceDifferentTokens(): void
    {
        self::assertNotSame(
            $this->manager->generateForAction('action_a'),
            $this->manager->generateForAction('action_b'),
        );
    }

    public function testDifferentKeysRejectEachOther(): void
    {
        $otherManager = $this->managerWithBinding(self::BINDING, sodium_crypto_auth_keygen());

        self::assertFalse($otherManager->validate($this->manager->generate()));
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
        $future = $this->craftMaskedToken(time() + 3600, '_default', self::BINDING);

        self::assertFalse($this->manager->validate($future));
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
        $manager = new StatelessCsrfManager($this->key, static fn(): string => self::BINDING, windowSeconds: 7200);

        self::assertTrue($manager->validate($manager->generate()));
    }

    public function testCustomDefaultAction(): void
    {
        $manager = new StatelessCsrfManager($this->key, static fn(): string => self::BINDING, defaultAction: 'my_form');
        $token = $manager->generate();

        self::assertTrue($manager->validateForAction($token, 'my_form'));
        self::assertFalse($manager->validateForAction($token, '_default'));
    }

    public function testDefaultWindowIs600Seconds(): void
    {
        self::assertSame(600, $this->manager->__debugInfo()['windowSeconds']);
    }

    public function testTokenExpiredAfter600SecondsDefault(): void
    {
        $expired = $this->craftMaskedToken(time() - 601, '_default', self::BINDING);

        self::assertFalse($this->manager->validate($expired));
    }

    public function testTokenValidWithin600SecondsDefault(): void
    {
        $recent = $this->craftMaskedToken(time() - 599, '_default', self::BINDING);

        self::assertTrue($this->manager->validate($recent));
    }
}
