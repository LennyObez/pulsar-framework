<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Internal\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;
use Pulsar\Extension\SocialSso\Internal\Session\SessionNonceVerifier;
use Pulsar\Security\Session\SessionInterface;

use function array_key_exists;
use function strlen;

#[CoversClass(SessionNonceVerifier::class)]
final class SessionNonceVerifierTest extends TestCase
{
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->session = $this->createArraySession();
    }

    #[Test]
    public function generateReturnsHexString(): void
    {
        $verifier = new SessionNonceVerifier($this->session);

        $nonce = $verifier->generate();

        self::assertSame(64, strlen($nonce));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $nonce);
    }

    #[Test]
    public function verifyReturnsTrueForMatchingNonce(): void
    {
        $verifier = new SessionNonceVerifier($this->session);

        $nonce = $verifier->generate();

        $claims = new IdTokenClaims(
            sub: 'user-1',
            iss: 'https://issuer.com',
            aud: 'client-id',
            exp: 9999999999,
            iat: 1000000000,
            nonce: $nonce,
        );

        self::assertTrue($verifier->verify($nonce, $claims));
    }

    #[Test]
    public function verifyConsumesNonceOnFirstUse(): void
    {
        $verifier = new SessionNonceVerifier($this->session);

        $nonce = $verifier->generate();

        $claims = new IdTokenClaims(
            sub: 'user-1',
            iss: 'https://issuer.com',
            aud: 'client-id',
            exp: 9999999999,
            iat: 1000000000,
            nonce: $nonce,
        );

        self::assertTrue($verifier->verify($nonce, $claims));
        self::assertFalse($verifier->verify($nonce, $claims));
    }

    #[Test]
    public function verifyReturnsFalseForMismatchedNonce(): void
    {
        $verifier = new SessionNonceVerifier($this->session);

        $nonce = $verifier->generate();

        $claims = new IdTokenClaims(
            sub: 'user-1',
            iss: 'https://issuer.com',
            aud: 'client-id',
            exp: 9999999999,
            iat: 1000000000,
            nonce: 'wrong-nonce-value',
        );

        self::assertFalse($verifier->verify($nonce, $claims));
    }

    #[Test]
    public function verifyReturnsFalseWhenClaimsHaveNullNonce(): void
    {
        $verifier = new SessionNonceVerifier($this->session);

        $nonce = $verifier->generate();

        $claims = new IdTokenClaims(
            sub: 'user-1',
            iss: 'https://issuer.com',
            aud: 'client-id',
            exp: 9999999999,
            iat: 1000000000,
            nonce: null,
        );

        self::assertFalse($verifier->verify($nonce, $claims));
    }

    #[Test]
    public function verifyReturnsFalseForUnknownNonce(): void
    {
        $verifier = new SessionNonceVerifier($this->session);

        $claims = new IdTokenClaims(
            sub: 'user-1',
            iss: 'https://issuer.com',
            aud: 'client-id',
            exp: 9999999999,
            iat: 1000000000,
            nonce: 'never-generated-nonce',
        );

        self::assertFalse($verifier->verify('never-generated-nonce', $claims));
    }

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

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
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
