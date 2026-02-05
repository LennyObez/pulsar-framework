<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Deploy\DeployCheck;

use function bin2hex;
use function count;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;

#[CoversClass(Kernel::class)]
final class KernelDeployCheckRegistrationTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_deploy_reg_test_' . bin2hex(random_bytes(8));
        mkdir($this->configPath, 0o750, true);
        $this->writeConfigStubs($this->configPath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->configPath);
    }

    #[Test]
    public function deployChecksAreRegisteredAfterBoot(): void
    {
        $configManager = new ConfigManager($this->configPath);
        $kernel = new Kernel(configManager: $configManager);

        $kernel->boot();

        $container = $kernel->container();
        self::assertTrue($container->has(DeployCheck::class));

        /** @var DeployCheck $deployCheck */
        $deployCheck = $container->get(DeployCheck::class);
        $checks = $deployCheck->checks();

        // Should have registered checks (at least the ones whose deps are available)
        // Without FrameworkCache: cache-settings and filesystem-scan become SkippedCheck
        // Without IntegrityConfig: integrity becomes SkippedCheck
        // All others should be real checks
        self::assertGreaterThanOrEqual(10, count($checks));
    }

    #[Test]
    public function disabledChecksAreNotRegistered(): void
    {
        // Write deploy config that disables jit and opcache
        file_put_contents(
            $this->configPath . DIRECTORY_SEPARATOR . 'deploy.php',
            "<?php\nreturn ['checks' => ['jit' => ['enabled' => false, 'severity' => 'warn'], 'opcache' => ['enabled' => false, 'severity' => 'fail']]];\n",
        );

        $configManager = new ConfigManager($this->configPath);
        $kernel = new Kernel(configManager: $configManager);

        $kernel->boot();

        /** @var DeployCheck $deployCheck */
        $deployCheck = $kernel->container()->get(DeployCheck::class);
        $checks = $deployCheck->checks();

        $names = array_map(static fn($c) => $c->getName(), $checks);

        self::assertNotContains('jit', $names);
        self::assertNotContains('opcache', $names);
    }

    private function writeConfigStubs(string $configPath): void
    {
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['name' => 'test', 'env' => 'testing', 'debug' => true, 'timezone' => 'UTC', 'locale' => 'en'];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'observability.php',
            "<?php\nreturn ['logging' => ['default_channel' => 'file', 'level' => 'info', 'channels' => []], 'metrics' => ['enabled' => false, 'exporters' => []], 'tracing' => ['enabled' => false, 'sampling_rate' => 0.0], 'error_tracking' => ['enabled' => false, 'max_groups' => 100, 'max_recent_events_per_group' => 5, 'sensitive_fields' => []], 'audit' => ['enabled' => false, 'log_path' => '/dev/null', 'events' => []]];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'security.php',
            "<?php\nreturn ['session' => ['cookie_name' => 'TEST', 'lifetime' => 3600, 'cookie_httponly' => true, 'cookie_secure' => false, 'cookie_samesite' => 'Lax', 'regenerate_on_privilege_change' => true], 'csrf' => ['enabled' => false, 'token_length' => 32, 'header_name' => 'X-CSRF-Token', 'form_field_name' => '_csrf'], 'headers' => [], 'rate_limiting' => ['enabled' => false, 'default_limit' => 60, 'default_window' => 60], 'auth' => ['default_guard' => 'session', 'guards' => [], 'two_factor' => ['enabled' => false, 'issuer' => 'Test', 'code_digits' => 6, 'code_period' => 30, 'verification_window' => 1, 'recovery_code_count' => 8], 'authorization' => ['roles' => [], 'super_roles' => []]]];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'deploy.php',
            "<?php\nreturn ['trusted_proxies' => [], 'request_limits' => ['max_post_size_mb' => 8, 'max_upload_size_mb' => 10], 'http3' => ['enabled' => false, 'alt_svc_max_age' => 86400]];\n",
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
