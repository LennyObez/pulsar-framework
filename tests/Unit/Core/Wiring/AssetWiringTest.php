<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AssetWiring;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

#[CoversClass(AssetWiring::class)]
final class AssetWiringTest extends TestCase
{
    private Router $router;

    #[Override]
    protected function setUp(): void
    {
        $this->router = new Router();

        $wiring = new AssetWiring();
        $wiring->wire(
            new Container(),
            new ConfigManager(),
            new MiddlewarePipeline(),
            new MiddlewareRegistry(),
            $this->router,
        );
    }

    #[Test]
    public function wireRegistersUiAssetRoute(): void
    {
        $matched = $this->router->match(Method::GET, '/ui/css/tokens.css');

        self::assertSame('pulsar.assets.ui', $matched->getName());
        self::assertSame('css/tokens.css', $matched->parameters['path']);
    }

    #[Test]
    public function wireRegistersMultiSegmentUiPath(): void
    {
        $matched = $this->router->match(Method::GET, '/ui/fonts/montserrat-variable.woff2');

        self::assertSame('pulsar.assets.ui', $matched->getName());
        self::assertSame('fonts/montserrat-variable.woff2', $matched->parameters['path']);
    }

    #[Test]
    public function wireRegistersCmsAssetRoute(): void
    {
        $matched = $this->router->match(Method::GET, '/cms/assets/cms-public.css');

        self::assertSame('pulsar.assets.cms', $matched->getName());
    }
}
