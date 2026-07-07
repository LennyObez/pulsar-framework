<?php

declare(strict_types=1);

namespace Pulsar\Tests\Runner;

use Override;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Pulsar\Config\Environment;

/**
 * Resets {@see Environment::active()} before every test.
 *
 * The active environment is a process-global bound by ConfigManager at boot
 * (ADR-0033). In the test suite, any test that loads configuration binds it;
 * resetting before each test prevents that global from leaking into a later
 * test's {@see env()} resolution, keeping the suite order-independent.
 */
final class ResetActiveEnvironmentExtension implements Extension
{
    #[Override]
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            #[Override]
            public function notify(PreparationStarted $event): void
            {
                Environment::resetActive();
            }
        });
    }
}
