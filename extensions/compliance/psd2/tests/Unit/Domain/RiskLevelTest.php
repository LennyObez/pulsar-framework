<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Domain\RiskLevel;

final class RiskLevelTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('low', RiskLevel::Low->value);
        self::assertSame('medium', RiskLevel::Medium->value);
        self::assertSame('high', RiskLevel::High->value);
    }

    #[Test]
    public function fromValueRoundTrips(): void
    {
        self::assertSame(RiskLevel::Low, RiskLevel::from('low'));
        self::assertSame(RiskLevel::Medium, RiskLevel::from('medium'));
        self::assertSame(RiskLevel::High, RiskLevel::from('high'));
    }
}
