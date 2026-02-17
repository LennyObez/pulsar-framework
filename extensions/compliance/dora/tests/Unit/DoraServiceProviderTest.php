<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Dora\DoraServiceProvider;
use Pulsar\Extension\Dora\Internal\InMemoryIctAssetRegistry;
use Pulsar\Extension\Dora\Risk\IctAssetRegistryInterface;

#[CoversClass(DoraServiceProvider::class)]
final class DoraServiceProviderTest extends TestCase
{
    #[Test]
    public function registerBindsIctAssetRegistry(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())
            ->method('instance')
            ->with(
                IctAssetRegistryInterface::class,
                self::isInstanceOf(InMemoryIctAssetRegistry::class),
            );

        $provider = new DoraServiceProvider();
        $provider->register($container);
    }

    #[Test]
    public function providesListsRegistryInterface(): void
    {
        $provider = new DoraServiceProvider();
        $provides = $provider->provides();

        self::assertContains(IctAssetRegistryInterface::class, $provides);
    }
}
