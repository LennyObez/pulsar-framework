<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Internal\Persistence\DatabaseRecoveryCodeStore;
use Pulsar\Auth\Internal\Persistence\DatabaseTotpReplayGuard;
use Pulsar\Auth\Internal\Persistence\DatabaseTotpSecretStore;
use Pulsar\Auth\TwoFactor\InMemoryRecoveryCodeStore;
use Pulsar\Auth\TwoFactor\InMemoryTotpReplayGuard;
use Pulsar\Auth\TwoFactor\InMemoryTotpSecretStore;
use Pulsar\Auth\TwoFactor\RecoveryCodeStoreInterface;
use Pulsar\Auth\TwoFactor\TotpReplayGuardInterface;
use Pulsar\Auth\TwoFactor\TotpSecretStoreInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function putenv;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Where two-factor authentication keeps its state after a real boot.
 *
 * Every one of the three stores is chosen by the same eager test —
 * `$container->has(ConnectionManagerInterface::class)` — evaluated while `AuthWiring`
 * runs. Whether that answer is true is a property of where `DatabaseWiring` sits in
 * `WiringList`, and for as long as it sat behind `AuthWiring` the answer was false on
 * every installation: secrets, recovery codes and the replay guard all fell back to
 * process memory.
 *
 * Under any multi-process model that is not a weaker guarantee, it is no guarantee. An
 * enrolment confirmed by one worker is unknown to the next; a recovery code consumed on
 * one is still valid on the others; a replayed TOTP code arriving at a second worker
 * meets an empty guard and is accepted. ASVS 2.8.4 cannot hold, whatever the migration
 * that creates the tables does.
 *
 * The framework already said so. `AuthWiring`'s production guardrail logs a warning
 * naming each in-memory store on every boot, and it named all three — a correct signal
 * nobody was reading. This test is the same statement in a form that stops a release.
 */
final class TwoFactorPersistenceBootTest extends TestCase
{
    private string $tempDir = '';

    protected function tearDown(): void
    {
        putenv('PULSAR_MASTER_KEY');

        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            foreach (scandir($this->tempDir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink($this->tempDir . '/' . $entry);
                }
            }

            rmdir($this->tempDir);
        }

        $this->tempDir = '';
    }

    /**
     * All three, not just the replay guard. Fixing one and leaving the others is the
     * shape of repair this codebase has already produced twice.
     */
    #[Test]
    public function everyTwoFactorStoreIsDatabaseBackedWhenAConnectionIsConfigured(): void
    {
        $container = $this->boot()->container();

        self::assertInstanceOf(
            DatabaseTotpReplayGuard::class,
            $container->get(TotpReplayGuardInterface::class),
            'an in-memory replay guard accepts a replayed code on any other worker',
        );

        self::assertInstanceOf(
            DatabaseTotpSecretStore::class,
            $container->get(TotpSecretStoreInterface::class),
            'an in-memory secret store loses the enrolment the moment the request ends',
        );

        self::assertInstanceOf(
            DatabaseRecoveryCodeStore::class,
            $container->get(RecoveryCodeStoreInterface::class),
            'an in-memory recovery-code store leaves a consumed code valid on every other worker',
        );
    }

    /**
     * The other half of the claim, and the reason this file proves anything.
     *
     * A test that only ever sees the database-backed stores cannot tell whether it is
     * measuring the wiring or merely asserting a constant. With no connection configured
     * the same three resolutions must yield the in-memory implementations — which is also
     * the correct behaviour for an application that genuinely has no database, and the
     * state the production guardrail exists to warn about.
     */
    #[Test]
    public function everyTwoFactorStoreFallsBackToMemoryWhenNoConnectionIsConfigured(): void
    {
        $container = $this->boot(withDatabase: false)->container();

        self::assertInstanceOf(
            InMemoryTotpReplayGuard::class,
            $container->get(TotpReplayGuardInterface::class),
        );
        self::assertInstanceOf(
            InMemoryTotpSecretStore::class,
            $container->get(TotpSecretStoreInterface::class),
        );
        self::assertInstanceOf(
            InMemoryRecoveryCodeStore::class,
            $container->get(RecoveryCodeStoreInterface::class),
        );
    }

    private function boot(bool $withDatabase = true): Kernel
    {
        // TOTP secrets are only ever written encrypted, so without a master key the
        // database store cannot be built and the in-memory one is the honest fallback.
        // An earlier version of this test omitted the key and read the resulting
        // in-memory store as a wiring defect — it was a fixture that had not configured
        // the thing it was asserting about.
        putenv('PULSAR_MASTER_KEY=' . bin2hex(random_bytes(32)));

        // sys_get_temp_dir rather than a path under the project: the suite image runs as
        // a non-root user and `var/` is not writable there, which made an earlier version
        // of this fixture fail for a reason that had nothing to do with what it tests.
        $this->tempDir = sys_get_temp_dir() . '/pulsar_2fa_boot_' . uniqid();
        mkdir($this->tempDir, 0o750, true);

        file_put_contents(
            $this->tempDir . '/app.php',
            "<?php return ['name' => 'TestApp', 'env' => 'local', 'debug' => false, "
            . "'timezone' => 'UTC', 'locale' => 'en'];",
        );
        file_put_contents(
            $this->tempDir . '/observability.php',
            '<?php return ["logging" => ["default_channel" => "null", "level" => "debug", '
            . '"channels" => ["null" => ["driver" => "stream", "stream" => "php://memory"]]]];',
        );
        if ($withDatabase) {
            file_put_contents(
                $this->tempDir . '/database.php',
                '<?php return ["default" => "sqlite", "connections" => ["sqlite" => ['
                . '"driver" => "sqlite", "host" => "", "port" => 0, "database" => ":memory:", '
                . '"username" => "", "password" => "", "charset" => "utf8", "collation" => "", '
                . '"options" => []]]];',
            );
        }
        file_put_contents(
            $this->tempDir . '/security.php',
            '<?php return ["session" => [], "csrf" => ["enabled" => false], "headers" => [], '
            . '"auth" => ["two_factor" => ["enabled" => true]]];',
        );
        file_put_contents($this->tempDir . '/cache.php', '<?php return ["enabled" => true];');

        $kernel = new Kernel(configManager: new ConfigManager(configPath: $this->tempDir));
        $kernel->boot();

        return $kernel;
    }
}
