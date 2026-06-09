<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Internal\Session;

use NoDiscard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Internal\Session\SessionOAuthStateManager;
use Pulsar\Security\Session\SessionInterface;

use function array_key_exists;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function strlen;

#[CoversClass(SessionOAuthStateManager::class)]
final class SessionOAuthStateManagerTest extends TestCase
{
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->session = $this->createArraySession();
    }

    #[Test]
    public function generateReturnsHexString(): void
    {
        $manager = new SessionOAuthStateManager($this->session);

        $state = $manager->generate();

        self::assertSame(64, strlen($state)); // 32 bytes = 64 hex chars
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $state);
    }

    #[Test]
    public function verifyReturnsTrueForValidState(): void
    {
        $manager = new SessionOAuthStateManager($this->session);

        $state = $manager->generate();

        self::assertTrue($manager->verify($state));
    }

    #[Test]
    public function verifyConsumesStateOnFirstUse(): void
    {
        $manager = new SessionOAuthStateManager($this->session);

        $state = $manager->generate();
        self::assertTrue($manager->verify($state));

        // Second use should fail; consumed
        self::assertFalse($manager->verify($state));
    }

    #[Test]
    public function verifyReturnsFalseForInvalidState(): void
    {
        $manager = new SessionOAuthStateManager($this->session);

        self::assertFalse($manager->verify('nonexistent-state-value'));
    }

    #[Test]
    public function verifyReturnsFalseForExpiredState(): void
    {
        $manager = new SessionOAuthStateManager($this->session, ttlSeconds: 0);

        $state = $manager->generate();

        // Sleep 1 second to ensure expiry (TTL=0 means immediate expiry)
        sleep(1);

        self::assertFalse($manager->verify($state));
    }

    #[Test]
    public function storePkceVerifierAndRetrieve(): void
    {
        $manager = new SessionOAuthStateManager($this->session);

        $state = $manager->generate();
        $manager->storePkceVerifier($state, 'my-pkce-verifier-abc');

        $retrieved = $manager->retrievePkceVerifier($state);

        self::assertSame('my-pkce-verifier-abc', $retrieved);
    }

    #[Test]
    public function retrievePkceVerifierConsumesOnFirstRead(): void
    {
        $manager = new SessionOAuthStateManager($this->session);

        $state = $manager->generate();
        $manager->storePkceVerifier($state, 'verifier-xyz');

        self::assertSame('verifier-xyz', $manager->retrievePkceVerifier($state));
        self::assertNull($manager->retrievePkceVerifier($state));
    }

    #[Test]
    public function retrievePkceVerifierReturnsNullForUnknownState(): void
    {
        $manager = new SessionOAuthStateManager($this->session);

        self::assertNull($manager->retrievePkceVerifier('unknown-state'));
    }

    #[Test]
    public function pkceVerifierIsolatedByState(): void
    {
        $manager = new SessionOAuthStateManager($this->session);

        $stateA = $manager->generate();
        $stateB = $manager->generate();

        $manager->storePkceVerifier($stateA, 'verifier-A');
        $manager->storePkceVerifier($stateB, 'verifier-B');

        self::assertSame('verifier-A', $manager->retrievePkceVerifier($stateA));
        self::assertSame('verifier-B', $manager->retrievePkceVerifier($stateB));
    }

    /**
     * Create an in-memory session implementation backed by a simple array.
     */
    private function createArraySession(): SessionInterface
    {
        return new class implements SessionInterface {
            /** @var array<string, mixed> */
            private array $data = [];

            public function start(): void {}

            public function isStarted(): bool
            {
                return true;
            }

            #[NoDiscard]
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function getString(string $key, string $default = ''): string
            {
                $value = $this->data[$key] ?? null;

                return is_string($value) ? $value : $default;
            }

            public function getNullableString(string $key): ?string
            {
                $value = $this->data[$key] ?? null;

                return is_string($value) ? $value : null;
            }

            public function getInt(string $key, int $default = 0): int
            {
                $value = $this->data[$key] ?? null;

                if (is_int($value)) {
                    return $value;
                }

                if (is_string($value) && is_numeric($value)) {
                    return (int) $value;
                }

                return $default;
            }

            public function getBool(string $key, bool $default = false): bool
            {
                $value = $this->data[$key] ?? null;

                return is_bool($value) ? $value : $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }

            public function remove(string $key): void
            {
                unset($this->data[$key]);
            }

            public function id(): string
            {
                return 'test-session-id';
            }

            public function regenerate(bool $deleteOldSession = true): void {}

            public function destroy(): void
            {
                $this->data = [];
            }

            /** @return array<string, mixed> */
            public function all(): array
            {
                return $this->data;
            }
        };
    }
}
