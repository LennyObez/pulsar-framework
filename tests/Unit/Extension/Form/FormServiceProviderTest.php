<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Form\Binding\FormDataBinder;
use Pulsar\Extension\Form\Binding\PropertyAccessor;
use Pulsar\Extension\Form\Builder\FormBuilder;
use Pulsar\Extension\Form\Config\FormConfig;
use Pulsar\Extension\Form\Contract\FormRendererInterface;
use Pulsar\Extension\Form\Csrf\FormCsrfManager;
use Pulsar\Extension\Form\FormServiceProvider;
use Pulsar\Extension\Form\Renderer\HtmlFormRenderer;
use Pulsar\Extension\Form\Upload\FilenameSanitizer;
use Pulsar\Extension\Form\Upload\MimeSniffer;
use Pulsar\Extension\Form\Upload\UploadedFileHandler;
use Pulsar\Extension\Form\Wizard\ResumeTokenManager;

use function count;

#[CoversClass(FormServiceProvider::class)]
final class FormServiceProviderTest extends TestCase
{
    #[Test]
    public function providesListsAllBoundServiceIds(): void
    {
        $provider = new FormServiceProvider();
        $provides = $provider->provides();

        $expected = [
            FormConfig::class,
            FormCsrfManager::class,
            FormRendererInterface::class,
            HtmlFormRenderer::class,
            FormBuilder::class,
            PropertyAccessor::class,
            FormDataBinder::class,
            MimeSniffer::class,
            FilenameSanitizer::class,
            UploadedFileHandler::class,
            ResumeTokenManager::class,
        ];

        self::assertSame($expected, $provides);
    }

    #[Test]
    public function registerBindsEveryDeclaredService(): void
    {
        $provider = new FormServiceProvider();
        $boundIds = [];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')->willReturnCallback(
            static function (string $id) use (&$boundIds): void {
                $boundIds[] = $id;
            },
        );

        $provider->register($container);

        foreach ($provider->provides() as $serviceId) {
            self::assertContains(
                $serviceId,
                $boundIds,
                "Service '$serviceId' from provides() must be bound in register()",
            );
        }
    }

    #[Test]
    public function registerBindsExactlyExpectedCount(): void
    {
        $provider = new FormServiceProvider();
        $bindCount = 0;

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')->willReturnCallback(
            static function () use (&$bindCount): void {
                $bindCount++;
            },
        );

        $provider->register($container);

        self::assertSame(count($provider->provides()), $bindCount);
    }
}
