<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\Template\ModuleTemplates;

#[CoversClass(ModuleTemplates::class)]
final class ModuleTemplatesTest extends TestCase
{
    private ModuleTemplates $templates;

    protected function setUp(): void
    {
        $this->templates = new ModuleTemplates();
    }

    #[Test]
    public function serviceInterfaceContainsCorrectStructure(): void
    {
        $output = $this->templates->serviceInterface('Billing', 'App\\Billing');

        self::assertStringContainsString('namespace App\\Billing\\Contracts;', $output);
        self::assertStringContainsString('interface BillingServiceInterface', $output);
        self::assertStringContainsString('#[Api(since: \'1.0.0\')]', $output);
        self::assertStringContainsString('Public API contract for the Billing module.', $output);
    }

    #[Test]
    public function serviceImplementationContainsCorrectStructure(): void
    {
        $output = $this->templates->serviceImplementation('Billing', 'App\\Billing');

        self::assertStringContainsString('namespace App\\Billing\\Internal\\Infrastructure;', $output);
        self::assertStringContainsString('final readonly class BillingService implements BillingServiceInterface', $output);
        self::assertStringContainsString('use App\\Billing\\Contracts\\BillingServiceInterface;', $output);
    }

    #[Test]
    public function controllerContainsConstructorInjection(): void
    {
        $output = $this->templates->controller('Billing', 'App\\Billing');

        self::assertStringContainsString('namespace App\\Billing\\Controller;', $output);
        self::assertStringContainsString('final class BillingController', $output);
        self::assertStringContainsString('private BillingServiceInterface $billingService', $output);
        self::assertStringContainsString('public function index(ServerRequestInterface $request): Response', $output);
        self::assertStringContainsString("'module' => 'Billing'", $output);
    }

    #[Test]
    public function configContainsDtoWithFromArray(): void
    {
        $output = $this->templates->config('Billing', 'App\\Billing');

        self::assertStringContainsString('namespace App\\Billing\\Config;', $output);
        self::assertStringContainsString('final readonly class BillingConfig', $output);
        self::assertStringContainsString('public bool $enabled = true', $output);
        self::assertStringContainsString('public static function fromArray(array $data): self', $output);
        self::assertStringContainsString('#[NoDiscard]', $output);
    }

    #[Test]
    public function serviceProviderBindsInterfaceToImplementation(): void
    {
        $output = $this->templates->serviceProvider('Billing', 'App\\Billing');

        self::assertStringContainsString('namespace App\\Billing;', $output);
        self::assertStringContainsString('class ModuleServiceProvider implements ServiceProviderInterface', $output);
        self::assertStringContainsString('$container->bind(BillingServiceInterface::class, BillingService::class)', $output);
        self::assertStringContainsString('BillingServiceInterface::class', $output);
    }

    #[Test]
    public function routesContainsRouterConfiguration(): void
    {
        $output = $this->templates->routes('Billing', 'App\\Billing');

        self::assertStringContainsString('use Pulsar\\Routing\\Router;', $output);
        self::assertStringContainsString('$router->get(\'/billing\'', $output);
        self::assertStringContainsString('[BillingController::class, \'index\']', $output);
        self::assertStringContainsString("'billing.index'", $output);
    }

    #[Test]
    public function readmeContainsModuleStructure(): void
    {
        $output = $this->templates->readme('Billing');

        self::assertStringContainsString('# Billing Module', $output);
        self::assertStringContainsString('Contracts/', $output);
        self::assertStringContainsString('Internal/Infrastructure/', $output);
        self::assertStringContainsString('Controller/', $output);
        self::assertStringContainsString('Config/', $output);
    }

    #[Test]
    public function serviceTestContainsPhpunitStructure(): void
    {
        $output = $this->templates->serviceTest('Billing', 'App\\Billing');

        self::assertStringContainsString('namespace Pulsar\\Tests\\Unit\\Modules\\Billing\\Internal\\Infrastructure;', $output);
        self::assertStringContainsString('#[CoversClass(BillingService::class)]', $output);
        self::assertStringContainsString('it_implements_the_service_interface', $output);
        self::assertStringContainsString('assertInstanceOf(BillingServiceInterface::class', $output);
    }

    #[Test]
    public function controllerTestContainsPhpunitStructure(): void
    {
        $output = $this->templates->controllerTest('Billing', 'App\\Billing');

        self::assertStringContainsString('namespace Pulsar\\Tests\\Unit\\Modules\\Billing\\Controller;', $output);
        self::assertStringContainsString('#[CoversClass(BillingController::class)]', $output);
        self::assertStringContainsString('it_returns_json_response', $output);
        self::assertStringContainsString('createStub(BillingServiceInterface::class)', $output);
    }
}
