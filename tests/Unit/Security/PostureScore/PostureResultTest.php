<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\PostureScore;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\PostureScore\PostureResult;
use Pulsar\Security\PostureScore\SecurityControl;

#[CoversClass(PostureResult::class)]
#[CoversClass(SecurityControl::class)]
final class PostureResultTest extends TestCase
{
    // ── PostureResult ───────────────────────────────────────────────

    #[Test]
    public function storesConstructorProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $controls = ['csrf_enabled' => true, 'mfa_configured' => false];
        $recommendations = ['mfa_configured' => 'Enable MFA for admin accounts'];

        $result = new PostureResult(
            score: 85,
            controls: $controls,
            recommendations: $recommendations,
            evaluatedAt: $now,
        );

        self::assertSame(85, $result->score);
        self::assertSame($controls, $result->controls);
        self::assertSame($recommendations, $result->recommendations);
        self::assertSame($now, $result->evaluatedAt);
    }

    #[Test]
    #[DataProvider('gradeProvider')]
    public function gradeMatchesScoreRange(int $score, string $expectedGrade): void
    {
        $result = new PostureResult(
            score: $score,
            controls: [],
            recommendations: [],
            evaluatedAt: new DateTimeImmutable(),
        );

        self::assertSame($expectedGrade, $result->grade());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function gradeProvider(): iterable
    {
        yield 'perfect score' => [100, 'A'];
        yield 'grade A boundary' => [90, 'A'];
        yield 'grade B high' => [89, 'B'];
        yield 'grade B boundary' => [80, 'B'];
        yield 'grade C high' => [79, 'C'];
        yield 'grade C boundary' => [70, 'C'];
        yield 'grade D high' => [69, 'D'];
        yield 'grade D boundary' => [60, 'D'];
        yield 'grade F high' => [59, 'F'];
        yield 'grade F zero' => [0, 'F'];
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $now = new DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $result = new PostureResult(
            score: 92,
            controls: ['csrf_enabled' => true],
            recommendations: [],
            evaluatedAt: $now,
        );

        $array = $result->toArray();

        self::assertSame(92, $array['score']);
        self::assertSame('A', $array['grade']);
        self::assertSame(['csrf_enabled' => true], $array['controls']);
        self::assertSame([], $array['recommendations']);
        self::assertSame($now->format('c'), $array['evaluated_at']);
    }

    // ── SecurityControl ─────────────────────────────────────────────

    #[Test]
    public function securityControlHasFourteenCases(): void
    {
        self::assertCount(14, SecurityControl::cases());
    }

    #[Test]
    #[DataProvider('controlWeightProvider')]
    public function securityControlWeights(SecurityControl $control, int $expectedWeight): void
    {
        self::assertSame($expectedWeight, $control->weight());
    }

    /**
     * @return iterable<string, array{SecurityControl, int}>
     */
    public static function controlWeightProvider(): iterable
    {
        yield 'EncryptionAtRest' => [SecurityControl::EncryptionAtRest, 12];
        yield 'EncryptionInTransit' => [SecurityControl::EncryptionInTransit, 12];
        yield 'MfaConfigured' => [SecurityControl::MfaConfigured, 10];
        yield 'CsrfEnabled' => [SecurityControl::CsrfEnabled, 8];
        yield 'RateLimitingEnabled' => [SecurityControl::RateLimitingEnabled, 8];
        yield 'HstsEnabled' => [SecurityControl::HstsEnabled, 8];
        yield 'SessionHardened' => [SecurityControl::SessionHardened, 8];
        yield 'AuditLoggingActive' => [SecurityControl::AuditLoggingActive, 8];
        yield 'CspEnabled' => [SecurityControl::CspEnabled, 6];
        yield 'CorsConfigured' => [SecurityControl::CorsConfigured, 5];
        yield 'ThreatDetectionEnabled' => [SecurityControl::ThreatDetectionEnabled, 5];
        yield 'ComplianceFrameworkEnabled' => [SecurityControl::ComplianceFrameworkEnabled, 4];
        yield 'PasswordPolicyStrong' => [SecurityControl::PasswordPolicyStrong, 4];
        yield 'ApiSigningEnabled' => [SecurityControl::ApiSigningEnabled, 2];
    }

    #[Test]
    public function totalWeightSumsToHundred(): void
    {
        $total = 0;
        foreach (SecurityControl::cases() as $control) {
            $total += $control->weight();
        }

        self::assertSame(100, $total);
    }

    #[Test]
    public function controlBackedValues(): void
    {
        self::assertSame('encryption_at_rest', SecurityControl::EncryptionAtRest->value);
        self::assertSame('api_signing_enabled', SecurityControl::ApiSigningEnabled->value);
    }
}
