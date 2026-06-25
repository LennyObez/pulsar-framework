<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\ManagedChallenge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallenge;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeService;

use function hash;
use function ord;
use function str_repeat;
use function strlen;
use function substr;
use function time;

#[CoversClass(ManagedChallengeService::class)]
#[CoversClass(ManagedChallenge::class)]
final class ManagedChallengeServiceTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef'; // 32 bytes
    private const int BITS = 8; // low difficulty for fast tests

    private function service(?TaggedCacheInterface $cache = null, int $bits = self::BITS, int $ttl = 300): ManagedChallengeService
    {
        return new ManagedChallengeService(self::KEY, $bits, $ttl, $cache);
    }

    /**
     * Brute-force a solution meeting the difficulty (mirrors the worker + server).
     */
    private function solve(string $id, int $bits): string
    {
        for ($i = 0; $i < 1_000_000; $i++) {
            if ($this->leadingZeroBits(hash('sha256', $id . '.' . $i, true)) >= $bits) {
                return (string) $i;
            }
        }

        self::fail('No solution found within bound');
    }

    private function findNonSolution(string $id, int $bits): string
    {
        for ($i = 0; $i < 1_000_000; $i++) {
            if ($this->leadingZeroBits(hash('sha256', $id . '.' . $i, true)) < $bits) {
                return (string) $i;
            }
        }

        self::fail('No non-solution found within bound');
    }

    private function leadingZeroBits(string $bytes): int
    {
        $count = 0;

        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $byte = ord($bytes[$i]);

            if ($byte === 0) {
                $count += 8;

                continue;
            }

            for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                if (($byte & $mask) !== 0) {
                    return $count;
                }

                $count++;
            }

            return $count;
        }

        return $count;
    }

    private function inMemoryCache(): TaggedCacheInterface
    {
        return new class implements TaggedCacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function invalidateTag(string $tag): void {}

            public function invalidateTags(array $tags): void {}
        };
    }

    #[Test]
    public function issues_a_parseable_signed_challenge(): void
    {
        $service = $this->service();
        $token = $service->issue();

        $challenge = $service->parse($token);

        self::assertNotNull($challenge);
        self::assertSame(self::BITS, $challenge->bits);
        self::assertSame(32, strlen($challenge->id)); // 16 bytes hex
        self::assertEqualsWithDelta(time(), $challenge->issuedAt, 2.0);
    }

    #[Test]
    public function verifies_a_correctly_solved_challenge(): void
    {
        $service = $this->service();
        $token = $service->issue();
        $challenge = $service->parse($token);
        self::assertNotNull($challenge);

        $solution = $this->solve($challenge->id, $challenge->bits);

        self::assertTrue($service->verify($token . '.' . $solution));
    }

    #[Test]
    public function rejects_a_tampered_challenge_token(): void
    {
        $service = $this->service();
        $token = $service->issue();

        // Flip the final character of the signed token.
        $tampered = substr($token, 0, -1) . ($token[strlen($token) - 1] === 'A' ? 'B' : 'A');

        self::assertNull($service->parse($tampered));
        self::assertFalse($service->verify($tampered . '.' . '12345'));
    }

    #[Test]
    public function rejects_an_expired_challenge(): void
    {
        $service = $this->service(ttl: 60);
        // Sign a challenge minted well outside the TTL window.
        $token = $service->sign(new ManagedChallenge('abcdef0123456789abcdef0123456789', self::BITS, time() - 600));
        $challenge = $service->parse($token);
        self::assertNotNull($challenge);
        $solution = $this->solve($challenge->id, $challenge->bits);

        self::assertFalse($service->verify($token . '.' . $solution));
    }

    #[Test]
    public function rejects_a_future_dated_challenge(): void
    {
        $service = $this->service();
        $token = $service->sign(new ManagedChallenge('abcdef0123456789abcdef0123456789', self::BITS, time() + 120));
        $solution = $this->solve('abcdef0123456789abcdef0123456789', self::BITS);

        self::assertFalse($service->verify($token . '.' . $solution));
    }

    #[Test]
    public function rejects_an_invalid_proof_of_work(): void
    {
        $service = $this->service();
        $token = $service->issue();
        $challenge = $service->parse($token);
        self::assertNotNull($challenge);

        $bad = $this->findNonSolution($challenge->id, $challenge->bits);

        self::assertFalse($service->verify($token . '.' . $bad));
    }

    #[Test]
    public function rejects_malformed_submissions(): void
    {
        $service = $this->service();

        self::assertFalse($service->verify(''));
        self::assertFalse($service->verify('no-separator'));
        self::assertFalse($service->verify($service->issue() . '.')); // empty solution
        self::assertFalse($service->verify($service->issue() . '.' . str_repeat('9', 100))); // oversized solution
    }

    #[Test]
    public function enforces_single_use_with_a_cache(): void
    {
        $service = $this->service($this->inMemoryCache());
        $token = $service->issue();
        $challenge = $service->parse($token);
        self::assertNotNull($challenge);
        $submitted = $token . '.' . $this->solve($challenge->id, $challenge->bits);

        self::assertTrue($service->verify($submitted), 'first use accepted');
        self::assertFalse($service->verify($submitted), 'replay rejected');
    }

    #[Test]
    public function allows_reuse_without_a_cache_best_effort(): void
    {
        $service = $this->service(); // no cache
        $token = $service->issue();
        $challenge = $service->parse($token);
        self::assertNotNull($challenge);
        $submitted = $token . '.' . $this->solve($challenge->id, $challenge->bits);

        self::assertTrue($service->verify($submitted));
        self::assertTrue($service->verify($submitted), 'no single-use enforcement without a cache');
    }

    #[Test]
    public function zero_difficulty_passes_proof_of_work_trivially(): void
    {
        $service = $this->service(bits: 0);
        $token = $service->issue();

        self::assertTrue($service->verify($token . '.' . '0'));
    }

    #[Test]
    public function has_valid_key_reflects_key_length(): void
    {
        self::assertTrue($this->service()->hasValidKey());
        self::assertFalse(new ManagedChallengeService('too-short')->hasValidKey());
    }
}
