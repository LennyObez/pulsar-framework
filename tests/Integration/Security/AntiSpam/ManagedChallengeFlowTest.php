<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeRenderer;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeService;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeVerifier;

use function hash;
use function ord;
use function preg_match;
use function strlen;

/**
 * End-to-end managed-challenge flow: the renderer mints a challenge, the
 * client solves the proof-of-work, and the verifier accepts it once — exactly
 * the path a real form submission takes, minus the browser.
 */
#[CoversClass(ManagedChallengeService::class)]
#[CoversClass(ManagedChallengeRenderer::class)]
#[CoversClass(ManagedChallengeVerifier::class)]
final class ManagedChallengeFlowTest extends TestCase
{
    private const int BITS = 10;

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

    private function solve(string $id, int $bits): string
    {
        for ($i = 0; $i < 1_000_000; $i++) {
            if ($this->leadingZeroBits(hash('sha256', $id . '.' . $i, true)) >= $bits) {
                return (string) $i;
            }
        }

        self::fail('no solution');
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

    #[Test]
    public function full_round_trip_render_solve_verify_then_replay_blocked(): void
    {
        $cache = $this->inMemoryCache();
        $service = new ManagedChallengeService('0123456789abcdef0123456789abcdef', self::BITS, 300, $cache);
        $renderer = new ManagedChallengeRenderer(
            $service,
            'pulsar-challenge-response',
            '/_pulsar/anti-spam/managed-challenge.js',
            '/_pulsar/anti-spam/managed-challenge.worker.js',
        );
        $verifier = new ManagedChallengeVerifier($service);

        // 1. Server renders the widget into a form.
        $html = $renderer->render('nonce-xyz');
        self::assertSame(1, preg_match('/data-pmc-challenge="([A-Za-z0-9_-]+)"/', $html, $tokenMatch));
        self::assertSame(1, preg_match('/data-pmc-id="([0-9a-f]{32})"/', $html, $idMatch));

        // 2. Client (worker) solves the proof-of-work for the exposed id.
        $solution = $this->solve($idMatch[1], self::BITS);
        $submittedToken = $tokenMatch[1] . '.' . $solution;

        // 3. Form submission carries the solved token in the captcha field.
        $context = new AntiSpamContext(
            body: 'A legitimate comment.',
            ipHash: 'iphash',
            captchaToken: $submittedToken,
        );

        $first = $verifier->check($context);
        self::assertTrue($first->passed, 'solved challenge accepted');

        // 4. Replaying the same token is rejected (single-use).
        $second = $verifier->check($context);
        self::assertFalse($second->passed, 'replayed challenge rejected');
    }
}
