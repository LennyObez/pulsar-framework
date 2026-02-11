<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use Pulsar\Extension\McpServer\Internal\Security\McpAccessGate;

#[CoversClass(McpAccessGate::class)]
final class McpAccessGateDeepTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        putenv('MCP_STAGING_CONFIRM');
        putenv('MCP_PRODUCTION_CONFIRM');
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_mcp_gate_test_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        putenv('MCP_STAGING_CONFIRM');
        putenv('MCP_PRODUCTION_CONFIRM');
        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $f) {
                if (is_dir($f)) {
                    rmdir($f);
                } else {
                    unlink($f);
                }
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function productionWithConfirmation1Passes(): void
    {
        $this->expectNotToPerformAssertions();

        putenv('MCP_PRODUCTION_CONFIRM=1');
        $env = Environment::load();
        $config = McpSecurityConfig::fromArray([]);

        $gate = new McpAccessGate($config, EnvironmentMode::Production, $env, $this->tmpDir);
        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function stagingWithConfirmation1Passes(): void
    {
        $this->expectNotToPerformAssertions();

        putenv('MCP_STAGING_CONFIRM=1');
        $env = Environment::load();
        $config = McpSecurityConfig::fromArray([]);

        $gate = new McpAccessGate($config, EnvironmentMode::Staging, $env, $this->tmpDir);
        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function pathTraversalRejected(): void
    {
        $config = McpSecurityConfig::fromArray(['path_allowlist' => ['*']]);
        $env = Environment::load();

        $gate = new McpAccessGate($config, EnvironmentMode::Local, $env, $this->tmpDir);

        $this->expectException(McpSecurityException::class);
        $gate->assertPathAllowed('../etc/passwd');
    }

    #[Test]
    public function pathMatchingAllowlist(): void
    {
        $this->expectNotToPerformAssertions();

        // Create a file in the tmp dir
        $file = $this->tmpDir . '/allowed.txt';
        file_put_contents($file, 'test');

        $config = McpSecurityConfig::fromArray(['path_allowlist' => ['*.txt']]);
        $env = Environment::load();

        $gate = new McpAccessGate($config, EnvironmentMode::Local, $env, $this->tmpDir);
        $gate->assertPathAllowed('allowed.txt');
    }

    #[Test]
    public function pathNotInAllowlistRejected(): void
    {
        $file = $this->tmpDir . '/secret.key';
        file_put_contents($file, 'secret');

        $config = McpSecurityConfig::fromArray(['path_allowlist' => ['*.txt']]);
        $env = Environment::load();

        $gate = new McpAccessGate($config, EnvironmentMode::Local, $env, $this->tmpDir);

        $this->expectException(McpSecurityException::class);
        $gate->assertPathAllowed('secret.key');
    }

    #[Test]
    public function nonExistentPathCheckedAgainstAllowlist(): void
    {
        $config = McpSecurityConfig::fromArray(['path_allowlist' => ['src/**']]);
        $env = Environment::load();

        $gate = new McpAccessGate($config, EnvironmentMode::Local, $env, $this->tmpDir);

        $this->expectException(McpSecurityException::class);
        $gate->assertPathAllowed('vendor/evil.php');
    }

    #[Test]
    public function releaseConcurrencySlotBelowZeroDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $config = McpSecurityConfig::fromArray(['max_concurrent_actions' => 1]);
        $env = Environment::load();

        $gate = new McpAccessGate($config, EnvironmentMode::Local, $env, $this->tmpDir);

        // Release without acquiring — should not go negative
        $gate->releaseConcurrencySlot();
        $gate->releaseConcurrencySlot();

        // Should still be able to acquire
        $gate->assertConcurrencyAllowed();
    }

    #[Test]
    public function emptyAllowlistRejectsAll(): void
    {
        $file = $this->tmpDir . '/test.txt';
        file_put_contents($file, 'data');

        $config = McpSecurityConfig::fromArray(['path_allowlist' => []]);
        $env = Environment::load();

        $gate = new McpAccessGate($config, EnvironmentMode::Local, $env, $this->tmpDir);

        $this->expectException(McpSecurityException::class);
        $gate->assertPathAllowed('test.txt');
    }
}
