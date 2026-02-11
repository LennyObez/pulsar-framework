<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Form\FormExtension;
use Pulsar\Extension\Form\FormServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(FormExtension::class)]
final class FormExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarForm(): void
    {
        $ext = new FormExtension();
        self::assertSame('pulsar/form', $ext->name());
    }

    #[Test]
    public function providersReturnsServiceProviderClass(): void
    {
        $ext = new FormExtension();
        self::assertSame([FormServiceProvider::class], $ext->providers());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $ext = new FormExtension();
        $ext->register($this->createStub(ContainerInterface::class));
    }

    #[Test]
    public function bootDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $ext = new FormExtension();
        $ext->boot($this->createStub(ContainerInterface::class), $this->createStub(RouterInterface::class));
    }
}
