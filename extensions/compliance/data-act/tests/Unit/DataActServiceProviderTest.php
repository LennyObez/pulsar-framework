<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\Access\DataAccessController;
use Pulsar\Extension\DataAct\Access\ThirdPartyAccessPolicy;
use Pulsar\Extension\DataAct\CloudSwitching\SwitchingAssistant;
use Pulsar\Extension\DataAct\Config\DataActConfig;
use Pulsar\Extension\DataAct\DataActServiceProvider;
use Pulsar\Extension\DataAct\Interoperability\InteroperabilityProfile;
use Pulsar\Extension\DataAct\Portability\DataPortabilityService;

#[CoversClass(DataActServiceProvider::class)]
final class DataActServiceProviderTest extends TestCase
{
    #[Test]
    public function providesListsAllRegisteredServices(): void
    {
        $provider = new DataActServiceProvider();

        $provides = $provider->provides();

        self::assertContains(DataActConfig::class, $provides);
        self::assertContains(DataPortabilityService::class, $provides);
        self::assertContains(InteroperabilityProfile::class, $provides);
        self::assertContains(SwitchingAssistant::class, $provides);
        self::assertContains(ThirdPartyAccessPolicy::class, $provides);
        self::assertContains(DataAccessController::class, $provides);
    }
}
