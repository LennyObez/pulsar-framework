<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Deploy\Check\SecurityHeadersReadinessCheck;
use Pulsar\Deploy\CheckSeverity;

#[CoversClass(SecurityHeadersReadinessCheck::class)]
final class SecurityHeadersReadinessCheckTest extends TestCase
{
    #[Test]
    public function getNameReturnsSecurityHeaders(): void
    {
        $check = new SecurityHeadersReadinessCheck(new SecurityHeadersConfig(headers: []));

        self::assertSame('security-headers', $check->getName());
    }

    #[Test]
    public function getDescriptionReturnsExpectedText(): void
    {
        $check = new SecurityHeadersReadinessCheck(new SecurityHeadersConfig(headers: []));

        self::assertStringContainsString('X-Content-Type-Options', $check->getDescription());
        self::assertStringContainsString('X-Frame-Options', $check->getDescription());
    }

    #[Test]
    public function passesWhenBothHeadersPresent(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
        $check = new SecurityHeadersReadinessCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('configured', $result->message);
    }

    #[Test]
    public function errorsWhenBothMissingInProduction(): void
    {
        $check = new SecurityHeadersReadinessCheck(new SecurityHeadersConfig(headers: []));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('X-Content-Type-Options', $result->message);
        self::assertStringContainsString('X-Frame-Options', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function warnsWhenBothMissingInStaging(): void
    {
        $check = new SecurityHeadersReadinessCheck(new SecurityHeadersConfig(headers: []));

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('Missing security headers', $result->message);
    }

    #[Test]
    public function passesInLocalEnvironmentEvenWithMissingHeaders(): void
    {
        $check = new SecurityHeadersReadinessCheck(new SecurityHeadersConfig(headers: []));

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function errorsWhenOnlyContentTypeOptionsMissingInProduction(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Frame-Options' => 'DENY',
        ]);
        $check = new SecurityHeadersReadinessCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('X-Content-Type-Options', $result->message);
        self::assertCount(1, $result->recommendations);
        self::assertStringContainsString('nosniff', $result->recommendations[0]);
    }

    #[Test]
    public function errorsWhenOnlyFrameOptionsMissingInProduction(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $check = new SecurityHeadersReadinessCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('X-Frame-Options', $result->message);
        self::assertCount(1, $result->recommendations);
        self::assertStringContainsString('clickjacking', $result->recommendations[0]);
    }

    #[Test]
    public function headerMatchIsCaseInsensitive(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ]);
        $check = new SecurityHeadersReadinessCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function bothMissingRecommendationsCoverBothHeaders(): void
    {
        $check = new SecurityHeadersReadinessCheck(new SecurityHeadersConfig(headers: []));

        $result = $check->check('production');

        self::assertCount(2, $result->recommendations);
        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('nosniff', $joined);
        self::assertStringContainsString('clickjacking', $joined);
    }
}
