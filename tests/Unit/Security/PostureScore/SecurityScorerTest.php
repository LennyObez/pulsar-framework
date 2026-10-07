<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\PostureScore;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\PostureScore\PostureCheckInterface;
use Pulsar\Security\PostureScore\PostureResult;
use Pulsar\Security\PostureScore\SecurityControl;
use Pulsar\Security\PostureScore\SecurityScorer;

#[CoversClass(SecurityScorer::class)]
#[CoversClass(PostureResult::class)]
final class SecurityScorerTest extends TestCase
{
    private function activeCheck(SecurityControl $control): PostureCheckInterface
    {
        $check = $this->createStub(PostureCheckInterface::class);
        $check->method('control')->willReturn($control);
        $check->method('isActive')->willReturn(true);
        $check->method('recommendation')->willReturn('');

        return $check;
    }

    private function inactiveCheck(SecurityControl $control, string $recommendation = 'Fix it'): PostureCheckInterface
    {
        $check = $this->createStub(PostureCheckInterface::class);
        $check->method('control')->willReturn($control);
        $check->method('isActive')->willReturn(false);
        $check->method('recommendation')->willReturn($recommendation);

        return $check;
    }

    #[Test]
    public function perfect_score_with_all_active(): void
    {
        $scorer = new SecurityScorer();
        $scorer->addCheck($this->activeCheck(SecurityControl::EncryptionAtRest));
        $scorer->addCheck($this->activeCheck(SecurityControl::CsrfEnabled));
        $scorer->addCheck($this->activeCheck(SecurityControl::HstsEnabled));

        $result = $scorer->evaluate();

        self::assertSame(100, $result->score);
        self::assertSame('A', $result->grade());
        self::assertSame([], $result->recommendations);
    }

    #[Test]
    public function deducts_weight_for_missing_controls(): void
    {
        $scorer = new SecurityScorer();
        $scorer->addCheck($this->inactiveCheck(SecurityControl::EncryptionAtRest, 'Enable encryption'));
        $scorer->addCheck($this->activeCheck(SecurityControl::CsrfEnabled));

        $result = $scorer->evaluate();

        self::assertSame(100 - SecurityControl::EncryptionAtRest->weight(), $result->score);
        self::assertArrayHasKey('encryption_at_rest', $result->recommendations);
        self::assertSame('Enable encryption', $result->recommendations['encryption_at_rest']);
    }

    #[Test]
    public function score_floors_at_zero(): void
    {
        $scorer = new SecurityScorer();

        foreach (SecurityControl::cases() as $control) {
            $scorer->addCheck($this->inactiveCheck($control));
        }

        $result = $scorer->evaluate();

        self::assertSame(0, $result->score);
        self::assertSame('F', $result->grade());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function gradeProvider(): iterable
    {
        yield 'A grade' => [95, 'A'];
        yield 'B grade' => [85, 'B'];
        yield 'C grade' => [75, 'C'];
        yield 'D grade' => [65, 'D'];
        yield 'F grade' => [50, 'F'];
    }

    #[Test]
    #[DataProvider('gradeProvider')]
    public function grade_mapping(int $score, string $expectedGrade): void
    {
        $result = new PostureResult(
            score: $score,
            controls: [],
            recommendations: [],
            evaluatedAt: new DateTimeImmutable(),
        );

        self::assertSame($expectedGrade, $result->grade());
    }

    #[Test]
    public function to_array_includes_all_fields(): void
    {
        $result = new PostureResult(
            score: 80,
            controls: ['csrf_enabled' => true],
            recommendations: [],
            evaluatedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $array = $result->toArray();

        self::assertSame(80, $array['score']);
        self::assertSame('B', $array['grade']);
        self::assertSame(['csrf_enabled' => true], $array['controls']);
        self::assertSame([], $array['recommendations']);
        self::assertArrayHasKey('evaluated_at', $array);
    }

    #[Test]
    public function no_checks_yields_perfect_score(): void
    {
        $scorer = new SecurityScorer();
        $result = $scorer->evaluate();

        self::assertSame(100, $result->score);
    }

    #[Test]
    public function controls_map_reports_status(): void
    {
        $scorer = new SecurityScorer();
        $scorer->addCheck($this->activeCheck(SecurityControl::CsrfEnabled));
        $scorer->addCheck($this->inactiveCheck(SecurityControl::MfaConfigured));

        $result = $scorer->evaluate();

        self::assertTrue($result->controls['csrf_enabled']);
        self::assertFalse($result->controls['mfa_configured']);
    }

    #[Test]
    public function security_control_weights_are_positive(): void
    {
        foreach (SecurityControl::cases() as $control) {
            self::assertGreaterThan(0, $control->weight(), $control->name . ' weight must be positive');
        }
    }
}
