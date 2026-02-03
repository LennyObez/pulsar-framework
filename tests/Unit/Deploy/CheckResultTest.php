<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\CheckSeverity;

#[CoversClass(CheckResult::class)]
final class CheckResultTest extends TestCase
{
    #[Test]
    public function it_creates_a_passing_result_via_factory(): void
    {
        $result = CheckResult::pass('my-check', 'All good');

        self::assertSame('my-check', $result->name);
        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertSame('All good', $result->message);
        self::assertSame([], $result->recommendations);
    }

    #[Test]
    public function it_creates_a_warning_result_via_factory(): void
    {
        $result = CheckResult::warning('warn-check', 'Something is off', ['Fix it']);

        self::assertSame('warn-check', $result->name);
        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertSame('Something is off', $result->message);
        self::assertSame(['Fix it'], $result->recommendations);
    }

    #[Test]
    public function it_creates_an_error_result_via_factory(): void
    {
        $recommendations = ['Step 1', 'Step 2'];
        $result = CheckResult::error('err-check', 'Critical failure', $recommendations);

        self::assertSame('err-check', $result->name);
        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertSame('Critical failure', $result->message);
        self::assertSame($recommendations, $result->recommendations);
    }

    #[Test]
    public function it_creates_a_result_via_constructor(): void
    {
        $result = new CheckResult(
            name: 'direct',
            severity: CheckSeverity::Warning,
            message: 'Direct creation',
            recommendations: ['rec1'],
        );

        self::assertSame('direct', $result->name);
        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertSame('Direct creation', $result->message);
        self::assertSame(['rec1'], $result->recommendations);
    }

    #[Test]
    public function it_defaults_recommendations_to_empty_array(): void
    {
        $result = new CheckResult(
            name: 'no-recs',
            severity: CheckSeverity::Pass,
            message: 'No recommendations',
        );

        self::assertSame([], $result->recommendations);
    }

    #[Test]
    public function pass_factory_accepts_recommendations(): void
    {
        $result = CheckResult::pass('check', 'OK', ['Optional tip']);

        self::assertSame(['Optional tip'], $result->recommendations);
    }
}
