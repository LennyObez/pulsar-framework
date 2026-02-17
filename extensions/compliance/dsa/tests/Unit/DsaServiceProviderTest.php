<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\Config\DsaConfig;
use Pulsar\Extension\Dsa\ContentModeration\AppealHandler;
use Pulsar\Extension\Dsa\ContentModeration\ModerationLog;
use Pulsar\Extension\Dsa\DsaServiceProvider;
use Pulsar\Extension\Dsa\NoticeAction\NoticeAndActionHandler;
use Pulsar\Extension\Dsa\Transparency\TransparencyReportGenerator;
use Pulsar\Extension\Dsa\TrustedFlagger\TrustedFlaggerRegistry;

#[CoversClass(DsaServiceProvider::class)]
final class DsaServiceProviderTest extends TestCase
{
    #[Test]
    public function providesReturnsAllServiceClassNames(): void
    {
        $provider = new DsaServiceProvider();

        $provides = $provider->provides();

        self::assertContains(DsaConfig::class, $provides);
        self::assertContains(ModerationLog::class, $provides);
        self::assertContains(TrustedFlaggerRegistry::class, $provides);
        self::assertContains(AppealHandler::class, $provides);
        self::assertContains(TransparencyReportGenerator::class, $provides);
        self::assertContains(NoticeAndActionHandler::class, $provides);
    }
}
