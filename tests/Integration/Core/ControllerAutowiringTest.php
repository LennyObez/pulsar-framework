<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Controller\ReflectionControllerResolver;
use Pulsar\Core\Kernel;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Routing\RoutingException;

use function file_put_contents;
use function getcwd;
use function is_dir;
use function ltrim;
use function mkdir;
use function str_starts_with;
use function strlen;
use function substr;
use function uniqid;

/**
 * A controller whose constructor depends on an unbound project concrete must
 * resolve without the developer binding every concrete by hand — and when a
 * dependency genuinely cannot be resolved (an unbound interface), the error
 * must name the controller and the offending parameter, not just "no binding
 * found for X". Both halves matter: autowiring that resolves silently is only
 * usable if the case it cannot resolve reports where to look.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(ReflectionControllerResolver::class)]
final class ControllerAutowiringTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->tempDir = $cwd . '/var/tmp_pulsar_ctrl_autowire_' . uniqid();
        mkdir($this->tempDir, 0o775, true);

        file_put_contents($this->tempDir . '/app.php', "<?php return ['name' => 'TestApp', 'env' => 'local', 'debug' => true, 'timezone' => 'UTC', 'locale' => 'en'];");
        file_put_contents($this->tempDir . '/observability.php', '<?php return ["logging" => ["default_channel" => "null", "level" => "debug", "channels" => ["null" => ["driver" => "stream", "stream" => "php://memory"]]]];');
        file_put_contents($this->tempDir . '/security.php', '<?php return ["session" => [], "csrf" => ["enabled" => false], "headers" => [], "rate_limiting" => ["enabled" => false]];');
    }

    protected function tearDown(): void
    {
        $cwd = getcwd();
        if ($cwd === false || !is_dir($this->tempDir)) {
            return;
        }

        $relative = str_starts_with($this->tempDir, $cwd)
            ? ltrim(substr($this->tempDir, strlen($cwd)), '/\\')
            : $this->tempDir;
        $safe = SafePath::resolveUnderCwd($relative);

        if ($safe !== null) {
            new SafeFilesystem()->removeDirectoryRecursive($safe);
        }
    }

    private function kernel(): Kernel
    {
        $kernel = new Kernel(configManager: new ConfigManager(configPath: $this->tempDir));
        $kernel->boot();

        return $kernel;
    }

    #[Test]
    public function dispatchesControllerWithUnboundConcreteDependency(): void
    {
        $kernel = $this->kernel();
        // Neither the controller nor its concrete dependency is bound anywhere.
        $kernel->router()->get('/greet', [AutowiredController::class, 'greet']);

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: '/greet'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello from greeter', (string) $response->getBody());
    }

    #[Test]
    public function unresolvableInterfaceDependencyErrorNamesControllerAndParameter(): void
    {
        $resolver = new ReflectionControllerResolver($this->kernel()->container());

        try {
            $resolver->resolve(NeedsUnboundInterfaceController::class);
            self::fail('Expected RoutingException for the unresolvable interface dependency');
        } catch (RoutingException $e) {
            self::assertStringContainsString(NeedsUnboundInterfaceController::class, $e->getMessage());
            self::assertStringContainsString(GreeterContract::class, $e->getMessage());
            self::assertStringContainsString('greeter', $e->getMessage());
        }
    }
}

final class GreeterService
{
    public function greet(): string
    {
        return 'hello from greeter';
    }
}

final class AutowiredController
{
    public function __construct(private readonly GreeterService $greeter) {}

    public function greet(): Response
    {
        return Response::text($this->greeter->greet());
    }
}

interface GreeterContract {}

final class NeedsUnboundInterfaceController
{
    public function __construct(public readonly GreeterContract $greeter) {}
}
