<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make\Template;

/**
 * Template generator for make:feature scaffolding.
 */
final readonly class FeatureTemplates
{
    public function handlerInterface(string $feature, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Features\\$feature\\Contracts;

            use Pulsar\\Api\\Api;
            use Pulsar\\Http\\Request;
            use Pulsar\\Http\\Response;

            /**
             * Handler contract for the $feature feature.
             */
            #[Api]
            interface {$feature}HandlerInterface
            {
                public function handle(Request \$request): Response;
            }
            PHP;
    }

    public function handler(string $feature, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Features\\$feature;

            use Override;
            use Pulsar\\Http\\Request;
            use Pulsar\\Http\\Response;
            use $namespace\\Features\\$feature\\Contracts\\{$feature}HandlerInterface;

            final readonly class {$feature}Handler implements {$feature}HandlerInterface
            {
                #[Override]
                public function handle(Request \$request): Response
                {
                    return Response::json([
                        'feature' => '$feature',
                        'status' => 'ok',
                    ]);
                }
            }
            PHP;
    }

    public function handlerTest(string $feature, string $module, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Pulsar\\Tests\\Unit\\Modules\\$module\\Features\\$feature;

            use PHPUnit\\Framework\\Attributes\\CoversClass;
            use PHPUnit\\Framework\\Attributes\\Test;
            use PHPUnit\\Framework\\TestCase;
            use Pulsar\\Http\\Request;
            use Pulsar\\Http\\ResponseStatus;
            use $namespace\\Features\\$feature\\Contracts\\{$feature}HandlerInterface;
            use $namespace\\Features\\$feature\\{$feature}Handler;

            #[CoversClass({$feature}Handler::class)]
            final class {$feature}HandlerTest extends TestCase
            {
                #[Test]
                public function it_implements_the_handler_interface(): void
                {
                    \$handler = new {$feature}Handler();

                    self::assertInstanceOf({$feature}HandlerInterface::class, \$handler);
                }

                #[Test]
                public function it_returns_json_response(): void
                {
                    \$handler = new {$feature}Handler();
                    \$request = \$this->createStub(Request::class);

                    \$response = \$handler->handle(\$request);

                    self::assertSame(ResponseStatus::Ok, \$response->status);
                }
            }
            PHP;
    }

    public function routeEntry(string $feature, string $namespace, string $method): string
    {
        $lcFeature = lcfirst($feature);
        $routeMethod = strtolower($method);

        return <<<PHP

            // $feature feature
            \$router->$routeMethod('/$lcFeature', [{$feature}Handler::class, 'handle'], '$lcFeature.handle');
            PHP;
    }
}
