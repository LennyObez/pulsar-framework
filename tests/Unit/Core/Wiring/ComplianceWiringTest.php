<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ComplianceWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Stringable;

use function bin2hex;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ComplianceWiring::class)]
final class ComplianceWiringTest extends TestCase
{
    private string $configPath = '';

    protected function tearDown(): void
    {
        if ($this->configPath !== '' && is_dir($this->configPath)) {
            $this->cleanDir($this->configPath);
        }
    }

    /** The strictest session idle timeout PCI-DSS mandates, computed from the resolver. */
    private function pciDssIdleTimeout(): int
    {
        return new ComplianceProfileResolver()
            ->resolve([ComplianceFramework::PciDss])
            ->sessionIdleTimeout;
    }

    #[Test]
    public function resolvesAndRegistersTheProfileInTheContainer(): void
    {
        [$container] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 60]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        self::assertTrue($container->has(ComplianceProfile::class));
        /** @var ComplianceProfile $profile */
        $profile = $container->get(ComplianceProfile::class);
        self::assertTrue($profile->hasFramework(ComplianceFramework::PciDss));
    }

    #[Test]
    public function tightensSessionIdleTimeoutWhenOperatorIsLooser(): void
    {
        $expected = $this->pciDssIdleTimeout();

        [$container, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertSame($expected, $security->session->idleTimeout, 'repository config must be tightened');

        // The container bindings are replaced too, so a consumer reading either the
        // aggregate or the nested config sees the compliant value.
        /** @var SecurityConfig $containerSecurity */
        $containerSecurity = $container->get(SecurityConfig::class);
        self::assertSame($expected, $containerSecurity->session->idleTimeout);
        /** @var SessionConfig $containerSession */
        $containerSession = $container->get(SessionConfig::class);
        self::assertSame($expected, $containerSession->idleTimeout);
    }

    #[Test]
    public function doesNotChangeSessionTimeoutWhenOperatorIsAlreadyStricter(): void
    {
        [, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 60]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertSame(60, $security->session->idleTimeout);
    }

    #[Test]
    public function treatsDisabledTimeoutAsWeakestAndTightensIt(): void
    {
        $expected = $this->pciDssIdleTimeout();

        [, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 0]", // 0 = no idle expiry = weakest
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertSame($expected, $security->session->idleTimeout);
    }

    #[Test]
    public function strictModeFailsClosedOnANonCompliantSetting(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Compliance strict mode');

        $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999]",
            "'enabled_frameworks' => ['pci_dss'], 'verification' => ['strict_mode' => true]",
        );
    }

    #[Test]
    public function noEnabledFrameworksIsANoOp(): void
    {
        [$container, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999]",
            "'enabled_frameworks' => []",
        );

        // Profile still registered (baseline), but nothing is tightened.
        self::assertTrue($container->has(ComplianceProfile::class));
        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertSame(99999, $security->session->idleTimeout);
    }

    #[Test]
    public function tighteningEmitsABootWarning(): void
    {
        $spy = new ComplianceWarningSpyLogger();

        $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999]",
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
        );

        self::assertTrue(
            $spy->has('session idle timeout tightened'),
            'a tighten warning must be logged; got: ' . $spy->dump(),
        );
        self::assertTrue($spy->has('pci_dss'), 'the warning must name the active framework');
    }

    /**
     * Boot config through the ComplianceWiring loader exactly as the kernel does
     * (register loader, load), then wire it against a fresh container.
     *
     * @return array{0: Container, 1: ConfigManager}
     */
    private function bootAndWire(string $securityBody, string $complianceBody, ?LoggerInterface $logger = null): array
    {
        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_compliance_wiring_' . bin2hex(random_bytes(4));
        mkdir($this->configPath, 0o755, true);

        $this->write('app.php', '');
        $this->write('observability.php', '');
        $this->write('security.php', $securityBody);
        $this->write('compliance.php', $complianceBody);

        $configManager = new ConfigManager($this->configPath);
        $wiring = new ComplianceWiring();
        ConfigLoaderRegistrar::register($configManager, [$wiring]);
        $configManager->load();

        $container = new Container();
        if ($logger !== null) {
            $container->instance(LoggerInterface::class, $logger);
        }

        $wiring->wire($container, $configManager, new MiddlewarePipeline($container), new MiddlewareRegistry(), new Router());

        return [$container, $configManager];
    }

    private function write(string $file, string $body): void
    {
        file_put_contents($this->configPath . DIRECTORY_SEPARATOR . $file, '<?php return [' . $body . '];');
    }

    private function cleanDir(string $dir): void
    {
        $items = scandir($dir);

        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                is_dir($path) ? $this->cleanDir($path) : unlink($path);
            }
        }

        rmdir($dir);
    }
}

/**
 * Minimal PSR-3 logger that records warning messages for assertion.
 */
final class ComplianceWarningSpyLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $warnings = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::WARNING) {
            $this->warnings[] = (string) $message;
        }
    }

    public function has(string $needle): bool
    {
        foreach ($this->warnings as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function dump(): string
    {
        return $this->warnings === [] ? '(no warnings)' : implode(' | ', $this->warnings);
    }
}
