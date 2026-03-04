<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RateLimit\ClientReputation;

#[CoversClass(ClientReputation::class)]
final class ClientReputationTest extends TestCase
{
    #[Test]
    public function defaultReputationHasNeutralScore(): void
    {
        $reputation = ClientReputation::default();

        self::assertSame(1.0, $reputation->score);
        self::assertSame(0, $reputation->successCount);
        self::assertSame(0, $reputation->violationCount);
    }

    #[Test]
    public function multiplierReturnsCurrentScore(): void
    {
        $reputation = new ClientReputation(score: 1.5, successCount: 10, violationCount: 0);

        self::assertSame(1.5, $reputation->multiplier());
    }

    #[Test]
    public function recordSuccessIncrementsScoreAndCount(): void
    {
        $original = ClientReputation::default();
        $improved = $original->recordSuccess();

        self::assertSame(1.01, $improved->score);
        self::assertSame(1, $improved->successCount);
        self::assertSame(0, $improved->violationCount);
    }

    #[Test]
    public function recordSuccessDoesNotMutateOriginal(): void
    {
        $original = ClientReputation::default();
        (void) $original->recordSuccess();

        self::assertSame(1.0, $original->score);
        self::assertSame(0, $original->successCount);
    }

    #[Test]
    public function recordViolationDecrementsScoreAndIncrementsCount(): void
    {
        $original = ClientReputation::default();
        $degraded = $original->recordViolation();

        self::assertSame(0.8, $degraded->score);
        self::assertSame(0, $degraded->successCount);
        self::assertSame(1, $degraded->violationCount);
    }

    #[Test]
    public function recordViolationDoesNotMutateOriginal(): void
    {
        $original = ClientReputation::default();
        (void) $original->recordViolation();

        self::assertSame(1.0, $original->score);
        self::assertSame(0, $original->violationCount);
    }

    #[Test]
    public function scoreCapsAtMaximumAfterManySuccesses(): void
    {
        $reputation = new ClientReputation(score: 1.99, successCount: 99, violationCount: 0);
        $improved = $reputation->recordSuccess();

        self::assertSame(2.0, $improved->score);

        $stillCapped = $improved->recordSuccess();
        self::assertSame(2.0, $stillCapped->score);
    }

    #[Test]
    public function scoreFloorsAtMinimumAfterManyViolations(): void
    {
        $reputation = new ClientReputation(score: 0.30, successCount: 0, violationCount: 4);
        $degraded = $reputation->recordViolation();

        self::assertSame(0.25, $degraded->score);

        $stillFloored = $degraded->recordViolation();
        self::assertSame(0.25, $stillFloored->score);
    }

    #[Test]
    public function reputationRecoveryAfterViolation(): void
    {
        $reputation = ClientReputation::default()
            ->recordViolation()
            ->recordSuccess()
            ->recordSuccess();

        self::assertEqualsWithDelta(0.82, $reputation->score, 0.0001);
        self::assertSame(2, $reputation->successCount);
        self::assertSame(1, $reputation->violationCount);
    }

    #[Test]
    #[DataProvider('boundaryScoreProvider')]
    public function scoreBoundariesAreEnforced(float $startScore, string $action, float $expectedScore): void
    {
        $reputation = new ClientReputation(score: $startScore, successCount: 0, violationCount: 0);

        $result = match ($action) {
            'success' => $reputation->recordSuccess(),
            'violation' => $reputation->recordViolation(),
            default => self::fail("Unknown action: {$action}"),
        };

        self::assertSame($expectedScore, $result->score);
    }

    /**
     * @return iterable<string, array{float, string, float}>
     */
    public static function boundaryScoreProvider(): iterable
    {
        yield 'success at max stays at max' => [2.0, 'success', 2.0];
        yield 'violation at min stays at min' => [0.25, 'violation', 0.25];
        yield 'success near max caps correctly' => [1.995, 'success', 2.0];
        yield 'violation near min floors correctly' => [0.35, 'violation', 0.25];
    }
}
