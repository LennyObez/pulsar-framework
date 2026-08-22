<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ComplianceLoggingWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Log\Compliance\ComplianceLogSink;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\Sink\DeferredSink;
use Pulsar\Observability\Log\Sink\DeferredSinkInterface;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(ComplianceLoggingWiring::class)]
final class ComplianceLoggingWiringTest extends TestCase
{
    #[Test]
    public function attachesTheComplianceSinkWhenEnabledWithAMasterKey(): void
    {
        $container = new Container();
        $container->instance(MasterKey::class, MasterKey::fromHex(bin2hex(random_bytes(32))));
        $deferredSink = new DeferredSink();
        $container->instance(DeferredSinkInterface::class, $deferredSink);

        $logFile = str_replace('\\', '/', sys_get_temp_dir())
            . '/pulsar_compliance_' . bin2hex(random_bytes(4)) . '.log';

        $this->wire($container, "'compliance' => ['enabled' => true, 'path' => '{$logFile}']");

        self::assertTrue($container->has(ComplianceLogSink::class), 'compliance sink is bound');

        // End-to-end: an entry written to the logger's deferred sink must land
        // in the compliance file, proving the sink is actually attached.
        $deferredSink->write(new LogEntry(
            LogLevel::Info,
            'user login',
            ['email' => 'person@example.com'],
            'file',
            new DateTimeImmutable(),
        ));

        self::assertFileExists($logFile);
        unlink($logFile);
    }

    #[Test]
    public function staysDormantWhenDisabled(): void
    {
        $container = new Container();
        $container->instance(MasterKey::class, MasterKey::fromHex(bin2hex(random_bytes(32))));
        $deferredSink = new DeferredSink();
        $container->instance(DeferredSinkInterface::class, $deferredSink);

        $this->wire($container, "'compliance' => ['enabled' => false]");

        self::assertFalse($container->has(ComplianceLogSink::class));
    }

    #[Test]
    public function failsFastWhenEnabledWithoutAMasterKey(): void
    {
        // Fail-closed: an operator who believes log output is masked for a
        // regulation must never silently log unmasked data.
        $container = new Container();
        $container->instance(DeferredSinkInterface::class, new DeferredSink());

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('master key');

        $this->wire($container, "'compliance' => ['enabled' => true]");
    }

    #[Test]
    public function failsFastOnAnUnknownFramework(): void
    {
        $container = new Container();
        $container->instance(MasterKey::class, MasterKey::fromHex(bin2hex(random_bytes(32))));
        $container->instance(DeferredSinkInterface::class, new DeferredSink());

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('unknown compliance framework');

        $this->wire($container, "'compliance' => ['enabled' => true, 'frameworks' => ['soc2']]");
    }

    private function wire(Container $container, string $complianceBlock): void
    {
        $configPath = sys_get_temp_dir() . '/pulsar_compliance_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents(
            $configPath . '/observability.php',
            "<?php return ['logging' => ['default_channel' => 'stderr', 'channels' => ['stderr' => ['driver' => 'stream', 'stream' => 'php://stderr']], {$complianceBlock}]];",
        );

        $configManager = new ConfigManager($configPath);
        $configManager->load();

        new ComplianceLoggingWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );
    }
}
