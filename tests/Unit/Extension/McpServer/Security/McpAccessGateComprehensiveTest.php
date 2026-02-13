<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use Pulsar\Extension\McpServer\Internal\Security\McpAccessGate;

use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Comprehensive tests for McpAccessGate covering environment blocking,
 * path allowlist enforcement with glob patterns, concurrency management,
 * path normalization, and adversarial path inputs.
 */
#[CoversClass(McpAccessGate::class)]
final class McpAccessGateComprehensiveTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        putenv('MCP_STAGING_CONFIRM');
        putenv('MCP_PRODUCTION_CONFIRM');
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp_gate_comp_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        putenv('MCP_STAGING_CONFIRM');
        putenv('MCP_PRODUCTION_CONFIRM');

        // Clean up files
        $files = glob($this->tmpDir . DIRECTORY_SEPARATOR . '*');
        if ($files !== false) {
            foreach ($files as $f) {
                if (is_dir($f)) {
                    $subFiles = glob($f . DIRECTORY_SEPARATOR . '*');
                    if ($subFiles !== false) {
                        foreach ($subFiles as $sf) {
                            unlink($sf);
                        }
                    }
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

    /**
     * @param list<string> $pathAllowlist
     */
    private function createGate(
        EnvironmentMode $mode = EnvironmentMode::Local,
        array $pathAllowlist = ['*'],
        int $maxConcurrent = 5,
    ): McpAccessGate {
        $config = McpSecurityConfig::fromArray([
            'path_allowlist' => $pathAllowlist,
            'max_concurrent_actions' => $maxConcurrent,
        ]);
        $env = Environment::load();

        return new McpAccessGate($config, $mode, $env, $this->tmpDir);
    }

    // --- Environment blocking ---

    #[Test]
    public function localEnvironmentAlwaysAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $gate = $this->createGate(EnvironmentMode::Local);
        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function stagingBlockedWithoutConfirmation(): void
    {
        $gate = $this->createGate(EnvironmentMode::Staging);

        $this->expectException(McpSecurityException::class);
        $this->expectExceptionMessage('staging');

        $gate->assertEnvironmentAllowed();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function stagingConfirmationValuesProvider(): array
    {
        return [
            'true string' => ['MCP_STAGING_CONFIRM', 'true'],
            'numeric 1' => ['MCP_STAGING_CONFIRM', '1'],
        ];
    }

    #[Test]
    #[DataProvider('stagingConfirmationValuesProvider')]
    public function stagingAllowedWithConfirmation(string $envVar, string $value): void
    {
        $this->expectNotToPerformAssertions();

        putenv($envVar . '=' . $value);
        $gate = $this->createGate(EnvironmentMode::Staging);
        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function stagingBlockedWithInvalidConfirmation(): void
    {
        putenv('MCP_STAGING_CONFIRM=yes'); // Not '1' or 'true'
        $gate = $this->createGate(EnvironmentMode::Staging);

        $this->expectException(McpSecurityException::class);

        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function productionBlockedWithoutConfirmation(): void
    {
        $gate = $this->createGate(EnvironmentMode::Production);

        $this->expectException(McpSecurityException::class);
        $this->expectExceptionMessage('production');

        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function productionAllowedWithTrue(): void
    {
        $this->expectNotToPerformAssertions();

        putenv('MCP_PRODUCTION_CONFIRM=true');
        $gate = $this->createGate(EnvironmentMode::Production);
        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function productionAllowedWith1(): void
    {
        $this->expectNotToPerformAssertions();

        putenv('MCP_PRODUCTION_CONFIRM=1');
        $gate = $this->createGate(EnvironmentMode::Production);
        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function productionBlockedWithFalse(): void
    {
        putenv('MCP_PRODUCTION_CONFIRM=false');
        $gate = $this->createGate(EnvironmentMode::Production);

        $this->expectException(McpSecurityException::class);

        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function productionBlockedWith0(): void
    {
        putenv('MCP_PRODUCTION_CONFIRM=0');
        $gate = $this->createGate(EnvironmentMode::Production);

        $this->expectException(McpSecurityException::class);

        $gate->assertEnvironmentAllowed();
    }

    // --- Path traversal ---

    #[Test]
    public function pathTraversalWithDoubleDotRejected(): void
    {
        $gate = $this->createGate(pathAllowlist: ['*']);

        $this->expectException(McpSecurityException::class);

        $gate->assertPathAllowed('../etc/passwd');
    }

    #[Test]
    public function pathWithDoubleDotInMiddleRejected(): void
    {
        $gate = $this->createGate(pathAllowlist: ['*']);

        $this->expectException(McpSecurityException::class);

        $gate->assertPathAllowed('src/../../../etc/shadow');
    }

    #[Test]
    public function pathWithEncodedDoubleDotRejected(): void
    {
        // The string "src/.." literally contains ".."
        $gate = $this->createGate(pathAllowlist: ['*']);

        $this->expectException(McpSecurityException::class);

        $gate->assertPathAllowed('src/..');
    }

    // --- Path allowlist ---

    #[Test]
    public function pathMatchingWildcardAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $file = $this->tmpDir . DIRECTORY_SEPARATOR . 'test.txt';
        file_put_contents($file, 'data');

        $gate = $this->createGate(pathAllowlist: ['*']);
        $gate->assertPathAllowed('test.txt');
    }

    #[Test]
    public function pathMatchingGlobPatternAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $file = $this->tmpDir . DIRECTORY_SEPARATOR . 'source.php';
        file_put_contents($file, '<?php');

        $gate = $this->createGate(pathAllowlist: ['*.php']);
        $gate->assertPathAllowed('source.php');
    }

    #[Test]
    public function pathNotMatchingPatternRejected(): void
    {
        $file = $this->tmpDir . DIRECTORY_SEPARATOR . 'config.yaml';
        file_put_contents($file, 'key: value');

        $gate = $this->createGate(pathAllowlist: ['*.php']);

        $this->expectException(McpSecurityException::class);

        $gate->assertPathAllowed('config.yaml');
    }

    #[Test]
    public function emptyAllowlistRejectsAll(): void
    {
        $file = $this->tmpDir . DIRECTORY_SEPARATOR . 'anything.txt';
        file_put_contents($file, 'data');

        $gate = $this->createGate(pathAllowlist: []);

        $this->expectException(McpSecurityException::class);

        $gate->assertPathAllowed('anything.txt');
    }

    #[Test]
    public function multipleAllowlistPatternsChecked(): void
    {
        $this->expectNotToPerformAssertions();

        $file = $this->tmpDir . DIRECTORY_SEPARATOR . 'style.css';
        file_put_contents($file, 'body{}');

        $gate = $this->createGate(pathAllowlist: ['*.php', '*.css', '*.js']);
        $gate->assertPathAllowed('style.css');
    }

    #[Test]
    public function nonExistentPathCheckedAgainstAllowlist(): void
    {
        $this->expectNotToPerformAssertions();

        // File doesn't exist, but matches allowlist pattern
        $gate = $this->createGate(pathAllowlist: ['*.php']);
        $gate->assertPathAllowed('new_file.php');
    }

    #[Test]
    public function nonExistentPathRejectedByAllowlist(): void
    {
        $gate = $this->createGate(pathAllowlist: ['*.php']);

        $this->expectException(McpSecurityException::class);

        $gate->assertPathAllowed('new_file.txt');
    }

    // --- Concurrency ---

    #[Test]
    public function concurrencyAllowsUpToLimit(): void
    {
        $this->expectNotToPerformAssertions();

        $gate = $this->createGate(maxConcurrent: 3);

        $gate->assertConcurrencyAllowed(); // 1
        $gate->assertConcurrencyAllowed(); // 2
        $gate->assertConcurrencyAllowed(); // 3
    }

    #[Test]
    public function concurrencyRejectsAboveLimit(): void
    {
        $gate = $this->createGate(maxConcurrent: 2);

        $gate->assertConcurrencyAllowed(); // 1
        $gate->assertConcurrencyAllowed(); // 2

        $this->expectException(McpSecurityException::class);
        $this->expectExceptionMessage('concurrent');

        $gate->assertConcurrencyAllowed(); // 3 - rejected
    }

    #[Test]
    public function concurrencySlotReleaseAllowsNewAction(): void
    {
        $this->expectNotToPerformAssertions();

        $gate = $this->createGate(maxConcurrent: 1);

        $gate->assertConcurrencyAllowed(); // 1/1
        $gate->releaseConcurrencySlot(); // 0/1
        $gate->assertConcurrencyAllowed(); // 1/1 again
    }

    #[Test]
    public function releaseMultipleSlotsTracksCorrectly(): void
    {
        $this->expectNotToPerformAssertions();

        $gate = $this->createGate(maxConcurrent: 3);

        $gate->assertConcurrencyAllowed(); // 1
        $gate->assertConcurrencyAllowed(); // 2
        $gate->assertConcurrencyAllowed(); // 3

        $gate->releaseConcurrencySlot(); // 2
        $gate->releaseConcurrencySlot(); // 1

        $gate->assertConcurrencyAllowed(); // 2
        $gate->assertConcurrencyAllowed(); // 3
    }

    #[Test]
    public function releaseBelowZeroDoesNotGoNegative(): void
    {
        $this->expectNotToPerformAssertions();

        $gate = $this->createGate(maxConcurrent: 1);

        // Release without acquiring
        $gate->releaseConcurrencySlot();
        $gate->releaseConcurrencySlot();

        // Should still be able to acquire
        $gate->assertConcurrencyAllowed();
    }

    #[Test]
    public function maxConcurrentOfOneIsStrictlySerial(): void
    {
        $gate = $this->createGate(maxConcurrent: 1);

        $gate->assertConcurrencyAllowed();

        $this->expectException(McpSecurityException::class);

        $gate->assertConcurrencyAllowed();
    }

    // --- Path with subdirectory patterns ---

    #[Test]
    public function multipleWildcardPatternsMatchDifferentExtensions(): void
    {
        $this->expectNotToPerformAssertions();

        $phpFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'app.php';
        $jsFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'app.js';
        file_put_contents($phpFile, '<?php');
        file_put_contents($jsFile, '// js');

        $gate = $this->createGate(pathAllowlist: ['*.php', '*.js']);
        $gate->assertPathAllowed('app.php');
        $gate->assertPathAllowed('app.js');
    }

    // --- Interface implementation ---

    #[Test]
    public function implementsMcpAccessGateInterface(): void
    {
        $gate = $this->createGate();

        self::assertInstanceOf(
            McpAccessGateInterface::class,
            $gate,
        );
    }

    // --- Environment exception messages ---

    #[Test]
    public function stagingExceptionContainsEnvironmentName(): void
    {
        $gate = $this->createGate(EnvironmentMode::Staging);

        try {
            $gate->assertEnvironmentAllowed();
            self::fail('Expected McpSecurityException');
        } catch (McpSecurityException $e) {
            self::assertStringContainsString('staging', $e->getMessage());
        }
    }

    #[Test]
    public function productionExceptionContainsEnvironmentName(): void
    {
        $gate = $this->createGate(EnvironmentMode::Production);

        try {
            $gate->assertEnvironmentAllowed();
            self::fail('Expected McpSecurityException');
        } catch (McpSecurityException $e) {
            self::assertStringContainsString('production', $e->getMessage());
        }
    }

    // --- Path exception contains original path ---

    #[Test]
    public function pathExceptionContainsOriginalPath(): void
    {
        $gate = $this->createGate(pathAllowlist: ['*.php']);

        try {
            $gate->assertPathAllowed('secret.key');
            self::fail('Expected McpSecurityException');
        } catch (McpSecurityException $e) {
            self::assertStringContainsString('secret.key', $e->getMessage());
        }
    }

    #[Test]
    public function pathTraversalExceptionContainsOriginalPath(): void
    {
        $gate = $this->createGate(pathAllowlist: ['*']);

        try {
            $gate->assertPathAllowed('../etc/passwd');
            self::fail('Expected McpSecurityException');
        } catch (McpSecurityException $e) {
            self::assertStringContainsString('../etc/passwd', $e->getMessage());
        }
    }
}
