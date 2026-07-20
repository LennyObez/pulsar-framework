<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\UnknownKeys;

use function array_filter;
use function dirname;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ConfigManager::class)]
#[CoversClass(UnknownKeys::class)]
#[CoversClass(SecurityConfig::class)]
final class UnknownKeyAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_unknownkey_' . uniqid();
        mkdir($this->tempDir, 0o750, true);
        putenv('PULSAR_CONFIG_STRICT');
        putenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        putenv('PULSAR_CONFIG_STRICT');
        putenv('APP_ENV');
        $this->cleanDir($this->tempDir);
    }

    #[Test]
    public function collectReturnsOnlyTheUnrecognizedKeys(): void
    {
        self::assertSame(
            ['typo', 'other'],
            UnknownKeys::collect(['known' => 1, 'typo' => 2, 'other' => 3], ['known', 'ignored']),
        );
    }

    #[Test]
    public function aNestedSessionTypoIsReportedAsADottedPath(): void
    {
        // The concrete case from the review: config/security.php used "driver"
        // where SessionConfig reads "handler".
        $config = SecurityConfig::fromArray(
            ['session' => ['driver' => 'redis', 'handler' => 'redis']],
            \Pulsar\Config\Environment::load(null),
        );

        self::assertContains('session.driver', $config->unknownConfigKeys());
        self::assertNotContains('session.handler', $config->unknownConfigKeys());
    }

    #[Test]
    public function warnModeCollectsUnknownKeysWithoutThrowing(): void
    {
        $this->writeConfigFiles(sessionExtra: '"driver" => "redis",');

        $manager = new ConfigManager(configPath: $this->tempDir);
        $manager->load();

        $warnings = $manager->unknownConfigKeyWarnings();
        $hit = array_filter(
            $warnings,
            static fn(string $w): bool => str_contains($w, 'security') && str_contains($w, 'session.driver'),
        );

        self::assertNotSame([], $hit, 'expected a warning for security session.driver. Got: ' . implode(' | ', $warnings));
    }

    #[Test]
    public function strictModeFailsClosedOnAnUnknownKey(): void
    {
        // config.strict_keys => true opts into fail-closed.
        $this->writeConfigFiles(sessionExtra: '"driver" => "redis",', appExtra: '"config" => ["strict_keys" => true],');

        $manager = new ConfigManager(configPath: $this->tempDir);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/session\.driver/');
        $manager->load();
    }

    #[Test]
    public function aCleanConfigProducesNoUnknownKeyWarnings(): void
    {
        $this->writeConfigFiles();

        $manager = new ConfigManager(configPath: $this->tempDir);
        $manager->load();

        self::assertSame([], $manager->unknownConfigKeyWarnings());
    }

    #[Test]
    public function theShippedDefaultConfigHasNoUnknownKeys(): void
    {
        // Drift guard: the framework's own config/*.php must stay in sync with
        // every migrated DTO's KNOWN_KEYS, or an enumeration gap would warn (and,
        // under strict mode, break) a clean install.
        $repoConfig = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config';
        self::assertDirectoryExists($repoConfig);

        $manager = new ConfigManager(configPath: $repoConfig);
        $manager->load();

        self::assertSame(
            [],
            $manager->unknownConfigKeyWarnings(),
            'The shipped config has keys not enumerated in a migrated DTO KNOWN_KEYS.',
        );
    }

    private function writeConfigFiles(string $sessionExtra = '', string $appExtra = ''): void
    {
        file_put_contents($this->tempDir . '/app.php', '<?php return [
            "name" => "TestApp", "env" => "local", "debug" => true,
            ' . $appExtra . '
        ];');

        file_put_contents($this->tempDir . '/observability.php', '<?php return [
            "logging" => ["default_channel" => "stderr", "level" => "debug", "channels" => [
                "stderr" => ["driver" => "stream", "stream" => "php://stderr"],
            ]],
            "audit" => ["enabled" => false, "log_path" => "var/logs/audit.jsonl", "events" => []],
        ];');

        file_put_contents($this->tempDir . '/security.php', '<?php return [
            "session" => [
                "cookie_name" => "TEST_SESSION", "lifetime" => 3600,
                ' . $sessionExtra . '
            ],
        ];');
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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
