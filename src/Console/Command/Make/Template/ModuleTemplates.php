<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make\Template;

/**
 * Template generator for make:module scaffolding.
 */
final readonly class ModuleTemplates
{
    public function serviceInterface(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Contracts;

            use Pulsar\\Api\\Api;

            /**
             * Public API contract for the $name module.
             */
            #[Api(since: '1.0.0')]
            interface {$name}ServiceInterface
            {
            }
            PHP;
    }

    public function serviceImplementation(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Internal\\Infrastructure;

            use Override;
            use $namespace\\Contracts\\{$name}ServiceInterface;

            /**
             * Default $name service implementation.
             */
            final readonly class {$name}Service implements {$name}ServiceInterface
            {
            }
            PHP;
    }

    public function controller(string $name, string $namespace): string
    {
        $lcName = lcfirst($name);
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Controller;

            use Psr\\Http\\Message\\ServerRequestInterface;
            use Pulsar\\Http\\Message\\Response;
            use $namespace\\Contracts\\{$name}ServiceInterface;

            final class {$name}Controller
            {
                public function __construct(
                    private {$name}ServiceInterface \${$lcName}Service,
                ) {}

                public function index(ServerRequestInterface \$request): Response
                {
                    return Response::json([
                        'module' => '$name',
                    ]);
                }
            }
            PHP;
    }

    public function config(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Config;

            use NoDiscard;
            use Pulsar\\Api\\Api;

            /**
             * Configuration DTO for the $name module.
             */
            #[Api(since: '1.0.0')]
            final readonly class {$name}Config
            {
                public function __construct(
                    public bool \$enabled = true,
                ) {}

                /**
                 * @param array<string, mixed> \$data
                 */
                #[NoDiscard]
                public static function fromArray(array \$data): self
                {
                    return new self(
                        enabled: (bool) (\$data['enabled'] ?? true),
                    );
                }
            }
            PHP;
    }

    public function serviceProvider(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace;

            use Pulsar\\Container\\ContainerInterface;
            use Pulsar\\Extensibility\\ServiceProviderInterface;
            use $namespace\\Contracts\\{$name}ServiceInterface;
            use $namespace\\Internal\\Infrastructure\\{$name}Service;

            final class ModuleServiceProvider implements ServiceProviderInterface
            {
                public function register(ContainerInterface \$container): void
                {
                    \$container->bind({$name}ServiceInterface::class, {$name}Service::class);
                }

                public function provides(): array
                {
                    return [
                        {$name}ServiceInterface::class,
                    ];
                }
            }
            PHP;
    }

    public function routes(string $name, string $namespace): string
    {
        $lcName = lcfirst($name);
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Pulsar\\Routing\\Router;
            use $namespace\\Controller\\{$name}Controller;

            return function (Router \$router): void {
                \$router->get('/$lcName', [{$name}Controller::class, 'index'], '$lcName.index');
            };
            PHP;
    }

    public function readme(string $name): string
    {
        return <<<MD
            # $name Module

            ## Structure

            - `Contracts/` — Public API interfaces (`#[Api(since: '1.0.0')]`)
            - `Internal/Infrastructure/` — Implementation details
            - `Controller/` — HTTP controllers
            - `Config/` — Configuration DTOs
            - `Middleware/` — HTTP middleware
            - `Models/` — Domain models
            - `Views/` — View templates
            MD;
    }

    public function serviceTest(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Pulsar\\Tests\\Unit\\Modules\\$name\\Internal\\Infrastructure;

            use PHPUnit\\Framework\\Attributes\\CoversClass;
            use PHPUnit\\Framework\\Attributes\\Test;
            use PHPUnit\\Framework\\TestCase;
            use $namespace\\Contracts\\{$name}ServiceInterface;
            use $namespace\\Internal\\Infrastructure\\{$name}Service;

            #[CoversClass({$name}Service::class)]
            final class {$name}ServiceTest extends TestCase
            {
                #[Test]
                public function it_implements_the_service_interface(): void
                {
                    \$service = new {$name}Service();

                    self::assertInstanceOf({$name}ServiceInterface::class, \$service);
                }
            }
            PHP;
    }

    public function controllerTest(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Pulsar\\Tests\\Unit\\Modules\\$name\\Controller;

            use PHPUnit\\Framework\\Attributes\\CoversClass;
            use PHPUnit\\Framework\\Attributes\\Test;
            use PHPUnit\\Framework\\TestCase;
            use Psr\\Http\\Message\\ServerRequestInterface;
            use Pulsar\\Http\\ResponseStatus;
            use $namespace\\Contracts\\{$name}ServiceInterface;
            use $namespace\\Controller\\{$name}Controller;

            #[CoversClass({$name}Controller::class)]
            final class {$name}ControllerTest extends TestCase
            {
                #[Test]
                public function it_returns_json_response(): void
                {
                    \$service = \$this->createStub({$name}ServiceInterface::class);
                    \$controller = new {$name}Controller(\$service);

                    \$request = \$this->createStub(ServerRequestInterface::class);
                    \$response = \$controller->index(\$request);

                    self::assertSame(ResponseStatus::OK->value, \$response->getStatusCode());
                }
            }
            PHP;
    }
}
