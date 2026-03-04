<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extension\Form\FormExtension;
use Pulsar\Extension\Form\FormServiceProvider;
use Pulsar\Routing\RouterInterface;

final class FormExtensionTest extends TestCase
{
    #[Test]
    public function implementsExtensionInterface(): void
    {
        self::assertInstanceOf(ExtensionInterface::class, new FormExtension());
    }

    #[Test]
    public function nameReturnsPulsarForm(): void
    {
        self::assertSame('pulsar/form', new FormExtension()->name());
    }

    #[Test]
    public function providersReturnsFormServiceProvider(): void
    {
        $providers = new FormExtension()->providers();

        self::assertCount(1, $providers);
        self::assertSame(FormServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootDoesNotRegisterRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        $router->expects(self::never())->method('get');
        $router->expects(self::never())->method('post');

        new FormExtension()->boot($container, $router);
    }
}
