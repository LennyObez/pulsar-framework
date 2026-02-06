<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Analyzer\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Analyzer\FindingSeverity;
use Pulsar\Http\Validation\Analyzer\Policy\ConsentAgeAnalyzer;

use function date;
use function strtotime;

#[CoversClass(ConsentAgeAnalyzer::class)]
final class ConsentAgeAnalyzerTest extends TestCase
{
    #[Test]
    public function underageDateProducesWarningFinding(): void
    {
        $analyzer = new ConsentAgeAnalyzer();
        $dob = date('Y-m-d', strtotime('-10 years'));

        $findings = $analyzer->analyze('dob', $dob, []);

        self::assertCount(1, $findings);
        self::assertSame(FindingSeverity::Warning, $findings[0]->severity);
        self::assertSame(0.7, $findings[0]->confidence);
        self::assertStringContainsString('age 10', $findings[0]->pattern);
        self::assertStringContainsString('minimum consent age 13', $findings[0]->pattern);
    }

    #[Test]
    public function integerAgeBelowMinimumProducesFinding(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        $findings = $analyzer->analyze('age', 10, []);

        self::assertCount(1, $findings);
        self::assertSame(FindingSeverity::Warning, $findings[0]->severity);
        self::assertSame(0.7, $findings[0]->confidence);
        self::assertStringContainsString('age 10', $findings[0]->pattern);
    }

    #[Test]
    public function integerAgeAtMinimumReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        self::assertSame([], $analyzer->analyze('age', 13, []));
    }

    #[Test]
    public function integerAgeAboveMinimumReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        self::assertSame([], $analyzer->analyze('age', 25, []));
    }

    #[Test]
    public function integerAgeZeroProducesFinding(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        $findings = $analyzer->analyze('age', 0, []);

        self::assertCount(1, $findings);
        self::assertStringContainsString('age 0', $findings[0]->pattern);
    }

    #[Test]
    public function negativeIntegerAgeReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        self::assertSame([], $analyzer->analyze('age', -5, []));
    }

    #[Test]
    public function adultDateReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer();
        $dob = date('Y-m-d', strtotime('-25 years'));

        self::assertSame([], $analyzer->analyze('dob', $dob, []));
    }

    #[Test]
    public function exactMinimumAgeDateReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer();
        $dob = date('Y-m-d', strtotime('-13 years'));

        self::assertSame([], $analyzer->analyze('dob', $dob, []));
    }

    #[Test]
    public function customMinimumAgeUnderageProducesFinding(): void
    {
        $analyzer = new ConsentAgeAnalyzer(minimumAge: 18);
        $dob = date('Y-m-d', strtotime('-16 years'));

        $findings = $analyzer->analyze('dob', $dob, []);

        self::assertCount(1, $findings);
        self::assertStringContainsString('minimum consent age 18', $findings[0]->pattern);
    }

    #[Test]
    public function customMinimumAgeAdultReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer(minimumAge: 18);
        $dob = date('Y-m-d', strtotime('-20 years'));

        self::assertSame([], $analyzer->analyze('dob', $dob, []));
    }

    #[Test]
    public function slashDateFormat(): void
    {
        $analyzer = new ConsentAgeAnalyzer();
        $dob = date('m/d/Y', strtotime('-10 years'));

        $findings = $analyzer->analyze('dob', $dob, []);

        self::assertCount(1, $findings);
        self::assertSame(FindingSeverity::Warning, $findings[0]->severity);
    }

    #[Test]
    public function futureDateReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer();
        $futureDate = date('Y-m-d', strtotime('+5 years'));

        self::assertSame([], $analyzer->analyze('dob', $futureDate, []));
    }

    #[Test]
    public function invalidDateStringReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        self::assertSame([], $analyzer->analyze('dob', 'not-a-date', []));
    }

    #[Test]
    public function emptyStringReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        self::assertSame([], $analyzer->analyze('dob', '', []));
    }

    #[Test]
    public function nullReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        self::assertSame([], $analyzer->analyze('dob', null, []));
    }

    #[Test]
    public function floatReturnsEmpty(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        self::assertSame([], $analyzer->analyze('age', 10.5, []));
    }

    #[Test]
    public function findingRecommendationMentionsCoppa(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        $findings = $analyzer->analyze('age', 10, []);

        self::assertCount(1, $findings);
        self::assertStringContainsString('COPPA', $findings[0]->recommendation);
    }

    #[Test]
    public function findingPreservesFieldName(): void
    {
        $analyzer = new ConsentAgeAnalyzer();

        $findings = $analyzer->analyze('user_age', 5, []);

        self::assertCount(1, $findings);
        self::assertSame('user_age', $findings[0]->field);
        self::assertStringContainsString('user_age', $findings[0]->recommendation);
    }
}
