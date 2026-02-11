<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Contract\AntivirusScanResult;

#[CoversClass(AntivirusScanResult::class)]
final class AntivirusScanResultTest extends TestCase
{
    #[Test]
    public function cleanFactoryProducesCleanResult(): void
    {
        $result = AntivirusScanResult::clean();

        self::assertTrue($result->clean);
        self::assertSame('', $result->threat);
    }

    #[Test]
    public function infectedFactoryProducesInfectedResult(): void
    {
        $result = AntivirusScanResult::infected('Trojan.GenericKD.12345');

        self::assertFalse($result->clean);
        self::assertSame('Trojan.GenericKD.12345', $result->threat);
    }

    #[Test]
    public function infectedResultWithEmptyThreat(): void
    {
        $result = AntivirusScanResult::infected('');

        self::assertFalse($result->clean);
        self::assertSame('', $result->threat);
    }
}
