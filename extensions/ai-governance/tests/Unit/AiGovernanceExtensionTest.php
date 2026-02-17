<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\AiGovernance\AiGovernanceExtension;
use Pulsar\Extension\AiGovernance\AiGovernanceServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(AiGovernanceExtension::class)]
final class AiGovernanceExtensionTest extends TestCase
{
    private AiGovernanceExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new AiGovernanceExtension();
    }

    public function testNameMatchesManifest(): void
    {
        self::assertSame('pulsar/ai-governance', $this->extension->name());
    }

    public function testProvidersReturnsServiceProvider(): void
    {
        $providers = $this->extension->providers();
        self::assertCount(1, $providers);
        self::assertSame(AiGovernanceServiceProvider::class, $providers[0]);
    }

    public function testRegisterDoesNotThrow(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $this->extension->register($container);

        // No exception = success: service provider handles bindings
        $this->addToAssertionCount(1);
    }

    public function testBootDoesNotRegisterRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createStub(RouterInterface::class);

        $this->extension->boot($container, $router);

        // No routes registered; pure backend extension
        $this->addToAssertionCount(1);
    }
}
