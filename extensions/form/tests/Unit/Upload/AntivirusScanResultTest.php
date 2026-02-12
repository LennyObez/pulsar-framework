<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Contract\AntivirusScanResult;

final class AntivirusScanResultTest extends TestCase
{
    #[Test]
    public function cleanFactoryReturnsCleanResult(): void
    {
        $result = AntivirusScanResult::clean();

        self::assertTrue($result->clean);
        self::assertSame('', $result->threat);
    }

    #[Test]
    public function infectedFactoryReturnsInfectedResult(): void
    {
        $result = AntivirusScanResult::infected('Trojan.Win32.Generic');

        self::assertFalse($result->clean);
        self::assertSame('Trojan.Win32.Generic', $result->threat);
    }
}
