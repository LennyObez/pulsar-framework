<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeploySeverity;

#[CoversNothing]
final class DeploySeverityTest extends TestCase
{
    #[Test]
    public function failMapsToError(): void
    {
        self::assertSame(CheckSeverity::Error, DeploySeverity::Fail->toCheckSeverity());
    }

    #[Test]
    public function warnMapsToWarning(): void
    {
        self::assertSame(CheckSeverity::Warning, DeploySeverity::Warn->toCheckSeverity());
    }

    #[Test]
    public function offMapsToPass(): void
    {
        self::assertSame(CheckSeverity::Pass, DeploySeverity::Off->toCheckSeverity());
    }

    #[Test]
    public function backedValues(): void
    {
        self::assertSame('fail', DeploySeverity::Fail->value);
        self::assertSame('warn', DeploySeverity::Warn->value);
        self::assertSame('off', DeploySeverity::Off->value);
    }

    #[Test]
    public function fromStringValue(): void
    {
        self::assertSame(DeploySeverity::Fail, DeploySeverity::from('fail'));
        self::assertSame(DeploySeverity::Warn, DeploySeverity::from('warn'));
        self::assertSame(DeploySeverity::Off, DeploySeverity::from('off'));
    }
}
