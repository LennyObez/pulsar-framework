<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Risk;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\AntiSpam\Risk\VelocityConfig;
use Pulsar\Security\AntiSpam\Risk\VelocitySignalProvider;

#[CoversClass(VelocitySignalProvider::class)]
final class VelocitySignalProviderTest extends TestCase
{
    #[Test]
    public function belowThresholdScoresZero(): void
    {
        $provider = new VelocitySignalProvider(
            new VelocityConfig(enabled: true, threshold: 5, windowSeconds: 60, maxScore: 1.0),
            $this->cache(),
            null,
            $this->fixedClock(1000),
        );

        $signal = $provider->evaluate($this->request('203.0.113.5'));

        self::assertSame(0.0, $signal->score);
        self::assertSame('velocity', $signal->source);
    }

    #[Test]
    public function aboveThresholdScoresGraduated(): void
    {
        $provider = new VelocitySignalProvider(
            new VelocityConfig(enabled: true, threshold: 2, windowSeconds: 60, maxScore: 1.0),
            $this->cache(),
            null,
            $this->fixedClock(1000),
        );
        $request = $this->request('203.0.113.5');

        $provider->evaluate($request); // count 1 => 0
        $provider->evaluate($request); // count 2 (== threshold) => 0
        $third = $provider->evaluate($request); // count 3 => overage (3-2)/2 = 0.5

        self::assertSame(0.5, $third->score);
    }

    #[Test]
    public function scoreIsCappedAtMaxScore(): void
    {
        $provider = new VelocitySignalProvider(
            new VelocityConfig(enabled: true, threshold: 1, windowSeconds: 60, maxScore: 0.9),
            $this->cache(),
            null,
            $this->fixedClock(1000),
        );
        $request = $this->request('203.0.113.5');

        for ($i = 0; $i < 9; $i++) {
            $provider->evaluate($request);
        }

        self::assertSame(0.9, $provider->evaluate($request)->score, 'score never exceeds maxScore');
    }

    #[Test]
    public function countsResetInANewWindow(): void
    {
        $now = 1000;
        $clock = function () use (&$now): int {
            return $now;
        };
        $provider = new VelocitySignalProvider(
            new VelocityConfig(enabled: true, threshold: 1, windowSeconds: 60, maxScore: 1.0),
            $this->cache(),
            null,
            $clock,
        );
        $request = $this->request('203.0.113.5');

        $provider->evaluate($request); // window A, count 1
        self::assertGreaterThan(0.0, $provider->evaluate($request)->score, 'second hit in window A is over threshold');

        $now += 120; // advance two windows
        self::assertSame(0.0, $provider->evaluate($request)->score, 'count resets in the new window');
    }

    #[Test]
    public function disabledScoresZero(): void
    {
        $provider = new VelocitySignalProvider(
            new VelocityConfig(enabled: false, threshold: 1),
            $this->cache(),
            null,
            $this->fixedClock(1000),
        );

        $provider->evaluate($this->request('203.0.113.5'));
        self::assertSame(0.0, $provider->evaluate($this->request('203.0.113.5'))->score);
    }

    #[Test]
    public function missingClientIpScoresZero(): void
    {
        $provider = new VelocitySignalProvider(
            new VelocityConfig(enabled: true, threshold: 1),
            $this->cache(),
            null,
            $this->fixedClock(1000),
        );

        // No REMOTE_ADDR and no trusted proxy => no client IP to key on.
        $request = new ServerRequest(method: 'GET', uri: '/');

        $provider->evaluate($request);
        self::assertSame(0.0, $provider->evaluate($request)->score);
    }

    private function fixedClock(int $now): Closure
    {
        return static fn(): int => $now;
    }

    private function request(string $remoteAddr): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/', serverParams: ['REMOTE_ADDR' => $remoteAddr]);
    }

    private function cache(): TaggedCacheInterface
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
}
