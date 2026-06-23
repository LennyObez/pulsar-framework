<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\FeatureFlag\FlagContext;
use Pulsar\FeatureFlag\FlagEvaluation;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagEvaluationReason;
use RuntimeException;

#[CoversClass(FlagEvaluationLog::class)]
final class FlagEvaluationLogTest extends TestCase
{
    #[Test]
    public function recordAndAllReturnsEvaluations(): void
    {
        $log = new FlagEvaluationLog();

        $evaluation = new FlagEvaluation(
            flagName: 'test-flag',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $log->record($evaluation);

        $all = $log->all();
        self::assertCount(1, $all);
        self::assertSame($evaluation, $all[0]);
    }

    #[Test]
    public function forFlagFiltersByName(): void
    {
        $log = new FlagEvaluationLog();

        $evalA = new FlagEvaluation(
            flagName: 'flag-a',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $evalB = new FlagEvaluation(
            flagName: 'flag-b',
            result: false,
            reason: FlagEvaluationReason::FlagDisabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $evalA2 = new FlagEvaluation(
            flagName: 'flag-a',
            result: false,
            reason: FlagEvaluationReason::FlagDisabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $log->record($evalA);
        $log->record($evalB);
        $log->record($evalA2);

        $flagAEvals = $log->forFlag('flag-a');

        self::assertCount(2, $flagAEvals);
        self::assertSame($evalA, $flagAEvals[0]);
        self::assertSame($evalA2, $flagAEvals[1]);

        $flagBEvals = $log->forFlag('flag-b');
        self::assertCount(1, $flagBEvals);
        self::assertSame($evalB, $flagBEvals[0]);
    }

    #[Test]
    public function clearEmptiesLog(): void
    {
        $log = new FlagEvaluationLog();

        $log->record(new FlagEvaluation(
            flagName: 'flag',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        ));

        self::assertCount(1, $log->all());

        $log->clear();

        self::assertCount(0, $log->all());
        self::assertSame([], $log->all());
    }

    #[Test]
    public function countReturnsCorrectCount(): void
    {
        $log = new FlagEvaluationLog();

        self::assertSame(0, $log->count());

        $log->record(new FlagEvaluation(
            flagName: 'first',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        ));

        self::assertSame(1, $log->count());

        $log->record(new FlagEvaluation(
            flagName: 'second',
            result: false,
            reason: FlagEvaluationReason::FlagDisabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        ));

        self::assertSame(2, $log->count());
    }

    #[Test]
    public function addObserverCallsObserverOnRecord(): void
    {
        $log = new FlagEvaluationLog();
        $observed = null;

        $log->addObserver(static function (FlagEvaluation $eval) use (&$observed): void {
            $observed = $eval;
        });

        $evaluation = new FlagEvaluation(
            flagName: 'test-flag',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $log->record($evaluation);

        self::assertSame($evaluation, $observed);
    }

    #[Test]
    public function observerExceptionDoesNotPreventRecording(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Feature flag evaluation observer failed', self::callback(
                static fn(array $context): bool => ($context['flag'] ?? null) === 'flag'
                    && ($context['exception'] ?? null) instanceof RuntimeException,
            ));

        $log = new FlagEvaluationLog($logger);

        $log->addObserver(static function (): void {
            throw new RuntimeException('observer failure');
        });

        $log->record(new FlagEvaluation(
            flagName: 'flag',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        ));

        self::assertSame(1, $log->count());
    }

    #[Test]
    public function resetRequestStateClearsLog(): void
    {
        $log = new FlagEvaluationLog();

        $log->record(new FlagEvaluation(
            flagName: 'flag',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        ));

        self::assertSame(1, $log->count());

        $log->resetRequestState();

        self::assertSame(0, $log->count());
    }
}
