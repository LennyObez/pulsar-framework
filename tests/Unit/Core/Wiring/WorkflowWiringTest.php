<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\WorkflowWiring;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Workflow\Engine\WorkflowEngineInterface;
use Pulsar\Workflow\Guard\GuardResolverInterface;
use Pulsar\Workflow\Internal\Engine\WorkflowEngine;
use Pulsar\Workflow\Storage\TransitionLogInterface;
use Pulsar\Workflow\Storage\WorkflowStorageInterface;
use Pulsar\Workflow\Timeout\TimeoutHandlerInterface;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(WorkflowWiring::class)]
final class WorkflowWiringTest extends TestCase
{
    #[Test]
    public function wiresTheEngineAndItsPortsWhenADatabaseIsAvailable(): void
    {
        $container = new Container();
        $connectionManager = $this->createStub(ConnectionManagerInterface::class);
        $connectionManager->method('connection')->willReturn($this->createStub(ConnectionInterface::class));
        $container->instance(ConnectionManagerInterface::class, $connectionManager);
        $container->instance(EventDispatcherInterface::class, $this->createStub(EventDispatcherInterface::class));

        $this->wire($container);

        self::assertTrue($container->has(WorkflowEngineInterface::class), 'engine interface is bound');
        self::assertInstanceOf(WorkflowEngine::class, $container->get(WorkflowEngineInterface::class));
        self::assertTrue($container->has(WorkflowStorageInterface::class), 'storage port is bound');
        self::assertTrue($container->has(TransitionLogInterface::class), 'transition log port is bound');
        self::assertTrue($container->has(GuardResolverInterface::class), 'guard resolver port is bound');
        self::assertTrue($container->has(TimeoutHandlerInterface::class), 'timeout handler port is bound');
    }

    #[Test]
    public function staysDormantWithoutADatabaseConnection(): void
    {
        // Workflow state is durable by design: no database, no engine.
        $container = new Container();
        $container->instance(EventDispatcherInterface::class, $this->createStub(EventDispatcherInterface::class));

        $this->wire($container);

        self::assertFalse($container->has(WorkflowEngineInterface::class));
        self::assertFalse($container->has(WorkflowStorageInterface::class));
    }

    #[Test]
    public function respectsAnApplicationBoundEngine(): void
    {
        $container = new Container();
        $custom = $this->createStub(WorkflowEngineInterface::class);
        $container->instance(WorkflowEngineInterface::class, $custom);

        $this->wire($container);

        self::assertSame($custom, $container->get(WorkflowEngineInterface::class));
        self::assertFalse($container->has(WorkflowStorageInterface::class), 'nothing else is bound');
    }

    private function wire(Container $container): void
    {
        $configPath = sys_get_temp_dir() . '/pulsar_workflow_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "stderr", "channels" => ["stderr" => ["driver" => "stream", "stream" => "php://stderr"]]]];');

        $configManager = new ConfigManager($configPath);
        $configManager->load();

        new WorkflowWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );
    }
}
