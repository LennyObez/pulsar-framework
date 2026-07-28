<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\ResilienceConfig;
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
#[CoversClass(ObservabilityConfig::class)]
#[CoversClass(DatabaseConfig::class)]
#[CoversClass(ResilienceConfig::class)]
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
            Environment::load(null),
        );

        self::assertContains('session.driver', $config->unknownConfigKeys());
        self::assertNotContains('session.handler', $config->unknownConfigKeys());
    }

    #[Test]
    public function aTypoTwoLevelsDeepIsReportedWithItsFullPath(): void
    {
        // Before nested reporting reached the leaves, only the top level of each
        // section was audited: a misspelled CSP directive silently left the policy
        // at its restrictive default with no signal at all.
        $config = SecurityConfig::fromArray(
            ['headers' => ['csp' => ['scripts_src' => "'self'", 'script_src' => "'self'"]]],
            Environment::load(null),
        );

        self::assertContains('headers.csp.scripts_src', $config->unknownConfigKeys());
        self::assertNotContains('headers.csp.script_src', $config->unknownConfigKeys());
    }

    #[Test]
    public function zeroTrustTyposAreReportedNowThatTheSectionIsAggregated(): void
    {
        // `zero_trust` was built but never folded into the parent's report, so its
        // unknown keys were computed and then dropped on the floor.
        $config = SecurityConfig::fromArray(
            [
                'zero_trust' => [
                    'trust_score_treshold' => 0.9,
                    'step_up' => ['max_attempt' => 3],
                ],
            ],
            Environment::load(null),
        );

        self::assertContains('zero_trust.trust_score_treshold', $config->unknownConfigKeys());
        self::assertContains('zero_trust.step_up.max_attempt', $config->unknownConfigKeys());
    }

    #[Test]
    public function aGuardTypoIsLabelledByTheGuardName(): void
    {
        $config = SecurityConfig::fromArray(
            ['auth' => ['guards' => [['name' => 'api', 'driver' => 'token', 'drivr' => 'token']]]],
            Environment::load(null),
        );

        self::assertContains('auth.guards.api.drivr', $config->unknownConfigKeys());
    }

    #[Test]
    public function aLiteralCustomHeaderIsNotReportedAsUnknown(): void
    {
        // The `headers` section has an OPEN key space by design — anything that is
        // not a known sub-config is a literal header to emit. Reporting those would
        // warn on every legitimate custom header at every boot.
        $config = SecurityConfig::fromArray(
            ['headers' => ['X-Robots-Tag' => 'noindex', 'Report-To' => 'default']],
            Environment::load(null),
        );

        self::assertSame([], $config->unknownConfigKeys());
    }

    #[Test]
    public function aLogChannelTypoIsReportedWithItsChannelName(): void
    {
        // logging and its channels have no DTO: they are read inline, so their keys
        // were never audited at all. A misspelled `path` silently sends the channel
        // to the driver default.
        $config = ObservabilityConfig::fromArray(
            ['logging' => ['channels' => ['file' => ['driver' => 'file', 'pathh' => 'var/logs/a.log']]]],
            Environment::load(null),
        );

        self::assertContains('logging.channels.file.pathh', $config->unknownConfigKeys());
    }

    #[Test]
    public function anUnrecognizedMetricsExporterNameIsReported(): void
    {
        // `promethius` matches neither exporter branch, so the exporter is simply
        // never configured — previously with no signal whatsoever.
        $config = ObservabilityConfig::fromArray(
            ['metrics' => ['exporters' => ['promethius' => ['enabled' => true]]]],
            Environment::load(null),
        );

        self::assertContains('metrics.exporters.promethius', $config->unknownConfigKeys());
    }

    #[Test]
    public function complianceLoggingReportsUnderItsRealPath(): void
    {
        // It is read from logging.compliance, not a top-level section; the reported
        // path has to match where the operator actually writes it.
        $config = ObservabilityConfig::fromArray(
            ['logging' => ['compliance' => ['enabled' => true, 'framework' => ['gdpr']]]],
            Environment::load(null),
        );

        self::assertContains('logging.compliance.framework', $config->unknownConfigKeys());
    }

    #[Test]
    public function resilienceSubsectionTyposAreReported(): void
    {
        $config = ResilienceConfig::fromArray(
            [
                'retry' => ['max_attempt' => 5],
                'circuit_breaker' => ['failure_treshold' => 3],
                'health_check' => ['timeout_second' => 2],
            ],
            Environment::load(null),
        );

        $unknown = $config->unknownConfigKeys();

        self::assertContains('retry.max_attempt', $unknown);
        self::assertContains('circuit_breaker.failure_treshold', $unknown);
        self::assertContains('health_check.timeout_second', $unknown);
    }

    #[Test]
    public function aConnectionTypoIsLabelledByTheConnectionName(): void
    {
        // connections is keyed by an operator-chosen name, so the report has to echo
        // that name back — "databse" alone would not say which connection to fix.
        $config = DatabaseConfig::fromArray(
            [
                'connections' => [
                    'mysql' => ['driver' => 'mysql', 'databse' => 'app'],
                ],
                'migrations' => ['tabel' => 'pulsar_migrations'],
            ],
            Environment::load(null),
            null,
        );

        $unknown = $config->unknownConfigKeys();

        self::assertContains('connections.mysql.databse', $unknown);
        self::assertContains('migrations.tabel', $unknown);
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
