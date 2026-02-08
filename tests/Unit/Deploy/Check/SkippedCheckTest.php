<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\Check\SkippedCheck;
use Pulsar\Deploy\CheckSeverity;

#[CoversClass(SkippedCheck::class)]
final class SkippedCheckTest extends TestCase
{
    #[Test]
    public function returnsWarningInNonProductionEnvironments(): void
    {
        $check = new SkippedCheck('test-check', 'Dependency not configured');

        $result = $check->check('staging');

        self::assertSame('test-check', $result->name);
        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('SKIPPED', $result->message);
    }

    #[Test]
    public function returnsConfiguredSeverityInProduction(): void
    {
        $check = new SkippedCheck('test-check', 'Missing service', CheckSeverity::Error);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function defaultProductionSeverityIsWarning(): void
    {
        $check = new SkippedCheck('cache-check', 'FrameworkCache not bound');

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
    }

    #[Test]
    public function nameAndDescriptionAreExposed(): void
    {
        $check = new SkippedCheck('my-check', 'Some reason');

        self::assertSame('my-check', $check->getName());
        self::assertStringContainsString('Some reason', $check->getDescription());
    }
}
