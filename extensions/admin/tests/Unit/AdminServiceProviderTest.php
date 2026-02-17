<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\AdminServiceProvider;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Gateway\AdminGateway;
use Pulsar\Extension\Admin\Internal\Security\AdminAccessGate;
use Pulsar\Extension\Admin\Internal\Security\AdminSafetyMode;

#[CoversClass(AdminServiceProvider::class)]
final class AdminServiceProviderTest extends TestCase
{
    #[Test]
    public function provides_returns_expected_classes(): void
    {
        $provider = new AdminServiceProvider();

        $provides = $provider->provides();

        self::assertContains(AdminConfig::class, $provides);
        self::assertContains(ResourceRegistryInterface::class, $provides);
        self::assertContains(ResourceQueryInterface::class, $provides);
        self::assertContains(ResourceMutatorInterface::class, $provides);
        self::assertContains(AdminGateway::class, $provides);
        self::assertContains(AdminAccessGate::class, $provides);
        self::assertContains(AdminSafetyMode::class, $provides);
    }

    #[Test]
    public function provides_returns_non_empty_array(): void
    {
        $provider = new AdminServiceProvider();

        self::assertNotEmpty($provider->provides());
    }
}
