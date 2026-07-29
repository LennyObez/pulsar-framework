<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Core\Wiring\DocumentationWiring;
use Pulsar\Documentation\DocumentationConfig;
use Pulsar\Documentation\DocVersion;
use Pulsar\Documentation\DocVersionRegistry;
use Pulsar\Documentation\DocVersionResolverMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

final class DocumentationWiringTest extends TestCase
{
    #[Test]
    public function wiresRegistryAndResolverWhenConfigured(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire(
            $container,
            $pipeline,
            "'enabled' => true, 'versions' => [['version' => '1.0', 'is_latest' => true], ['version' => '1.1']]",
        );

        self::assertTrue($container->has(DocVersionRegistry::class));
        self::assertTrue($container->has(DocVersionResolverMiddleware::class));
        self::assertSame($before + 1, $pipeline->count(), 'resolver middleware is piped');

        $registry = $container->get(DocVersionRegistry::class);
        self::assertInstanceOf(DocVersionRegistry::class, $registry);
        self::assertSame('1.1', $registry->resolve('1.1')->version);
    }

    #[Test]
    public function bindsConfigButDoesNothingElseWhenDisabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire($container, $pipeline, "'enabled' => false, 'versions' => [['version' => '1.0']]");

        self::assertTrue($container->has(DocumentationConfig::class), 'config is bound for introspection');
        self::assertFalse($container->has(DocVersionRegistry::class));
        self::assertSame($before, $pipeline->count());
    }

    #[Test]
    public function doesNothingWhenEnabledWithNoVersions(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        $this->wire($container, $pipeline, "'enabled' => true, 'versions' => []");

        self::assertFalse($container->has(DocVersionRegistry::class), 'no registry without versions');
    }

    #[Test]
    public function resolverAttachesTheVersionToTheRequest(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        $this->wire(
            $container,
            $pipeline,
            "'enabled' => true, 'versions' => [['version' => '2.0', 'is_latest' => true]]",
        );

        $resolver = $container->get(DocVersionResolverMiddleware::class);
        self::assertInstanceOf(DocVersionResolverMiddleware::class, $resolver);

        $handler = new class implements RequestHandlerInterface {
            public ?DocVersion $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $version = $request->getAttribute('doc_version');
                if ($version instanceof DocVersion) {
                    $this->seen = $version;
                }

                return Response::text('OK');
            }
        };

        $resolver->process(new ServerRequest(method: 'GET', uri: '/docs/2.0/getting-started'), $handler);

        self::assertSame('2.0', $handler->seen?->version);
    }

    private function wire(Container $container, MiddlewarePipeline $pipeline, string $body): void
    {
        $configPath = sys_get_temp_dir() . '/pulsar_docs_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        // DocumentationConfig now loads through the ConfigRepository
        // (ProvidesConfigLoaders), exactly as at boot: register the wiring's
        // loader and load(). load() requires the three mandatory config files.
        file_put_contents($configPath . '/app.php', '<?php return [];');
        file_put_contents($configPath . '/security.php', '<?php return [];');
        file_put_contents($configPath . '/observability.php', '<?php return [];');
        file_put_contents($configPath . '/documentation.php', "<?php return [$body];");

        $configManager = new ConfigManager($configPath);
        $wiring = new DocumentationWiring();
        ConfigLoaderRegistrar::register($configManager, [$wiring]);
        $configManager->load();

        $wiring->wire($container, $configManager, $pipeline, new MiddlewareRegistry(), new Router());
    }
}
