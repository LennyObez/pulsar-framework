<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Pulsar\Compliance\Verification\ComplianceVerificationEngine;
use Pulsar\Compliance\Verification\VerificationReport;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ComplianceVerificationWiring;
use Pulsar\Core\Wiring\ComplianceWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Stringable;

use function bin2hex;
use function file_put_contents;
use function getenv;
use function implode;
use function is_dir;
use function mkdir;
use function putenv;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ComplianceVerificationWiring::class)]
final class ComplianceVerificationWiringTest extends TestCase
{
    private string $configPath = '';

    /** @var array<string, string|false> */
    private array $savedEnvironment = [];

    /**
     * These cases assert on the connection they wrote, so the process environment must
     * not be in the room.
     *
     * ConnectionConfig::fromArray reads the environment BEFORE the config array —
     * `$environment->get('DB_HOST') ?? $data['host']` — so an exported DB_HOST wins
     * over an explicit one. CI exports DB_HOST=127.0.0.1 for its service containers,
     * which turned the Unix socket these tests configure into a TCP host and made
     * strict mode demand TLS. The assertion was about the runner, not the code.
     */
    protected function setUp(): void
    {
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE'] as $key) {
            $this->savedEnvironment[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }

        $this->savedEnvironment = [];

        if ($this->configPath !== '' && is_dir($this->configPath)) {
            $this->cleanDir($this->configPath);
        }
    }

    #[Test]
    public function registersTheEngineSoVerificationCanBeRerunOnDemand(): void
    {
        [$container] = $this->boot("'enabled_frameworks' => ['pci_dss']");

        self::assertTrue($container->has(ComplianceVerificationEngine::class));
    }

    #[Test]
    public function isInertWhenNoFrameworkIsEnabled(): void
    {
        // Opting out of compliance must not produce verification noise.
        [$container] = $this->boot("'enabled_frameworks' => []");

        self::assertFalse($container->has(ComplianceVerificationEngine::class));
    }

    #[Test]
    public function isInertWhenVerificationIsDisabled(): void
    {
        [$container] = $this->boot(
            "'enabled_frameworks' => ['pci_dss'], 'verification' => ['enabled' => false]",
        );

        self::assertFalse($container->has(ComplianceVerificationEngine::class));
    }

    #[Test]
    public function doesNotRunTheBootCheckWhenBootCheckIsOff(): void
    {
        // The engine is still available for on-demand runs, but no report is
        // produced at boot.
        [$container] = $this->boot(
            "'enabled_frameworks' => ['pci_dss'], 'verification' => ['boot_check' => false]",
        );

        self::assertTrue($container->has(ComplianceVerificationEngine::class));
        self::assertFalse($container->has(VerificationReport::class));
    }

    #[Test]
    public function reportsUnsatisfiedControlsAsBootWarnings(): void
    {
        // Nothing security-critical is wired in this harness (no master key, so no
        // SessionEncryption / MasterKey / AuditLogger bindings), so PCI-DSS
        // requirements the configuration cannot tighten must surface as warnings —
        // the whole point of verification rather than silent enforcement.
        $spy = new VerificationWarningSpy();

        [$container] = $this->boot("'enabled_frameworks' => ['pci_dss']", $spy);

        self::assertTrue($container->has(VerificationReport::class));
        self::assertTrue(
            $spy->has('boot verification failed'),
            'unsatisfiable requirements must be reported; got: ' . $spy->dump(),
        );
        self::assertTrue($spy->has('pci_dss'), 'the warning must name the active framework');
    }

    #[Test]
    public function strictModeRefusesTheBootWhenControlsAreUnsatisfied(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('boot-time verification failed');

        $this->boot("'enabled_frameworks' => ['pci_dss'], 'verification' => ['strict_mode' => true]");
    }

    #[Test]
    public function reportsDatabaseTlsAsMissingForANetworkConnectionWithoutSsl(): void
    {
        $spy = new VerificationWarningSpy();

        $this->boot(
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
            "'default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql', 'host' => 'db', 'database' => 'app', 'options' => []]]",
        );

        self::assertTrue($spy->has('runtime.db_tls'), 'a plaintext network connection must be reported; got: ' . $spy->dump());
    }

    #[Test]
    public function acceptsDatabaseTlsWhenSslModeRequiresIt(): void
    {
        $spy = new VerificationWarningSpy();

        $this->boot(
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
            "'default' => 'pg', 'connections' => ['pg' => ['driver' => 'pgsql', 'host' => 'db', 'database' => 'app', 'options' => ['sslmode' => 'require']]]",
        );

        self::assertFalse($spy->has('runtime.db_tls'), 'a TLS-configured connection must not be flagged; got: ' . $spy->dump());
    }

    /**
     * This case used to assert the opposite, and that is the whole defect: PDO indexes
     * driver options by integer attribute constant and discards string keys, so a MySQL
     * connection carrying `ssl_mode => require` connects in plaintext. Reporting PCI-DSS
     * encryption-in-transit as satisfied on the strength of a setting the driver never
     * receives is a false compliance pass, which is worse than reporting nothing.
     */
    #[Test]
    public function refusesToCountAStringSslModeOnMysqlWherePdoDiscardsIt(): void
    {
        $spy = new VerificationWarningSpy();

        $this->boot(
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
            "'default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql', 'host' => 'db', 'database' => 'app', 'options' => ['ssl_mode' => 'require']]]",
        );

        self::assertTrue(
            $spy->has('runtime.db_tls'),
            'MySQL never receives a string ssl_mode key, so it must not satisfy the requirement; got: ' . $spy->dump(),
        );
    }

    #[Test]
    public function rejectsWeakSslModesThatSilentlyFallBackToPlaintext(): void
    {
        // "prefer" lets the driver fall back to an unencrypted connection, so it
        // must not count as encryption in transit.
        $spy = new VerificationWarningSpy();

        $this->boot(
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
            "'default' => 'pg', 'connections' => ['pg' => ['driver' => 'pgsql', 'host' => 'db', 'database' => 'app', 'options' => ['sslmode' => 'prefer']]]",
        );

        self::assertTrue($spy->has('runtime.db_tls'), 'sslmode=prefer must not satisfy the requirement; got: ' . $spy->dump());
    }

    #[Test]
    public function doesNotDemandTlsFromAUnixSocketConnection(): void
    {
        // A path-like host is a Unix socket (libpq reads host=/var/run/postgresql
        // as a socket directory): local IPC, no transport to encrypt. Failing it
        // would make strict mode unsatisfiable for the very common
        // app-and-database-on-one-host deployment.
        $spy = new VerificationWarningSpy();

        $this->boot(
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
            "'default' => 'pg', 'connections' => ['pg' => ['driver' => 'pgsql', 'host' => '/var/run/postgresql', 'database' => 'app', 'options' => []]]",
        );

        self::assertFalse($spy->has('runtime.db_tls'), 'a Unix-socket connection must not be flagged; got: ' . $spy->dump());
    }

    #[Test]
    public function doesNotDemandTlsWhenTheHostIsEmptyAndTheDriverDefaultsToItsSocket(): void
    {
        $spy = new VerificationWarningSpy();

        $this->boot(
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
            "'default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql', 'host' => '', 'database' => 'app', 'options' => []]]",
        );

        self::assertFalse($spy->has('runtime.db_tls'), 'an empty host means the default socket; got: ' . $spy->dump());
    }

    #[Test]
    public function stillReportsLoopbackTcpWhichIsARealNetworkConnection(): void
    {
        // 127.0.0.1 is TCP, not IPC. Exempting it would turn the check into a
        // rubber stamp; reporting it only fails a boot under opt-in strict mode.
        $spy = new VerificationWarningSpy();

        $this->boot(
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
            "'default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'app', 'options' => []]]",
        );

        self::assertTrue($spy->has('runtime.db_tls'), 'loopback TCP must still be reported; got: ' . $spy->dump());
    }

    #[Test]
    public function doesNotDemandTlsFromSqliteWhichHasNoNetworkTransport(): void
    {
        // SQLite is a local file: requiring TLS of it would be meaningless, and
        // failing it would make compliance strict mode unsatisfiable for every
        // SQLite deployment.
        $spy = new VerificationWarningSpy();

        $this->boot(
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
            "'default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'options' => []]]",
        );

        self::assertFalse($spy->has('runtime.db_tls'), 'SQLite must not be flagged for missing TLS; got: ' . $spy->dump());
    }

    /**
     * Boot the compliance config through its loader, run ComplianceWiring (which
     * registers the profile) and then the verification wiring, exactly as the
     * kernel orders them.
     *
     * @return array{0: Container, 1: ConfigManager}
     */
    private function boot(
        string $complianceBody,
        ?LoggerInterface $logger = null,
        ?string $databaseBody = null,
    ): array {
        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_compliance_verify_' . bin2hex(random_bytes(4));
        mkdir($this->configPath, 0o755, true);

        $this->write('app.php', '');
        $this->write('observability.php', '');
        // two_factor is enabled so the unrelated MFA tightening control is
        // satisfied and cannot abort the boot before verification runs.
        $this->write('security.php', "'auth' => ['two_factor' => ['enabled' => true]]");
        $this->write('compliance.php', $complianceBody);

        if ($databaseBody !== null) {
            $this->write('database.php', $databaseBody);
        }

        $configManager = new ConfigManager($this->configPath);
        $complianceWiring = new ComplianceWiring();
        ConfigLoaderRegistrar::register($configManager, [$complianceWiring]);
        $configManager->load();

        $container = new Container();

        if ($logger !== null) {
            $container->instance(LoggerInterface::class, $logger);
        }

        $pipeline = new MiddlewarePipeline($container);
        $registry = new MiddlewareRegistry();
        $router = new Router();

        $complianceWiring->wire($container, $configManager, $pipeline, $registry, $router);
        new ComplianceVerificationWiring()->wire($container, $configManager, $pipeline, $registry, $router);

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
final class VerificationWarningSpy extends AbstractLogger
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
