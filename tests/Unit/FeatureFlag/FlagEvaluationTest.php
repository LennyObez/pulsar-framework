<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\FlagContext;
use Pulsar\FeatureFlag\FlagEvaluation;
use Pulsar\FeatureFlag\FlagEvaluationReason;

#[CoversClass(FlagEvaluation::class)]
final class FlagEvaluationTest extends TestCase
{
    #[Test]
    public function constructionAndFieldAccess(): void
    {
        $context = new FlagContext(tenantId: 'acme', userId: 'user-1');
        $evaluatedAt = new DateTimeImmutable('2025-01-15T12:00:00+00:00');

        $evaluation = new FlagEvaluation(
            flagName: 'test-flag',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: $context,
            evaluatedAt: $evaluatedAt,
        );

        self::assertSame('test-flag', $evaluation->flagName);
        self::assertTrue($evaluation->result);
        self::assertSame(FlagEvaluationReason::FlagEnabled, $evaluation->reason);
        self::assertSame($context, $evaluation->context);
        self::assertSame($evaluatedAt, $evaluation->evaluatedAt);
    }
}
