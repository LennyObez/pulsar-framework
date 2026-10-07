<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\BackupConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\BackupWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Resilience\Backup\BackupDestination;
use Pulsar\Resilience\Backup\BackupPlan;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The backup wiring fails closed, and refuses a configuration that could lie.
 *
 * TWO PROPERTIES, and both are about what a deployment is allowed to believe.
 *
 * With no key provider bound -- which is what a deployment with no
 * `PULSAR_MASTER_KEY` looks like, because `SecurityWiring` skips its whole crypto
 * block -- this wiring binds NOTHING. Not a service that writes unsealed
 * archives, not a plan, not a destination. That is the same posture every other
 * at-rest protection takes, and it is the only one that keeps the compliance
 * report honest: a deployment without a key must report a recovery gap.
 *
 * And a configured file tree may not take the `audit` id. `backup:run
 * --require-audit` decides whether the archive carries the tamper-evident trail
 * by asking the manifest whether anything under that prefix was written, so a
 * tree sharing the id answers a question it is not being asked.
 */
#[CoversClass(BackupWiring::class)]
final class BackupWiringTest extends TestCase
{
    private string $configPath = '';

    #[Override]
    protected function tearDown(): void
    {
        if ($this->configPath !== '' && is_dir($this->configPath)) {
            self::removeTree($this->configPath);
        }
    }

    #[Test]
    public function withNoKeyProviderNothingIsBoundAtAll(): void
    {
        $container = $this->wire(withKey: false);

        self::assertFalse(
            $container->has(BackupServiceInterface::class),
            'a deployment with no master key must get no backup service rather than an unsealed one',
        );
        self::assertFalse($container->has(BackupDestination::class));
        self::assertFalse($container->has(BackupPlan::class));
    }

    #[Test]
    public function withAKeyTheServiceAndTheDestinationAreBound(): void
    {
        $container = $this->wire(withKey: true);

        self::assertTrue($container->has(BackupServiceInterface::class));
        self::assertTrue($container->has(BackupDestination::class));
    }

    #[Test]
    public function theBackupConfigIsBoundEvenWhenThePrimitiveIsOff(): void
    {
        // The config is a fact about the deployment either way, and the report
        // reads it. What being off removes is the service, not the answer to
        // "is backup enabled here".
        $container = $this->wire(withKey: true, enabled: false);

        self::assertTrue($container->has(BackupConfig::class));
        self::assertFalse($container->has(BackupServiceInterface::class));
    }

    #[Test]
    public function aTreeMayNotTakeTheAuditId(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/reserved for the audit trail/');

        $this->wire(withKey: true, trees: [BackupPlan::AUDIT_SOURCE_ID => 'storage/somewhere-else']);
    }

    /**
     * @param array<non-empty-string, non-empty-string> $trees
     */
    private function wire(bool $withKey, bool $enabled = true, array $trees = []): Container
    {
        $container = new Container();

        if ($withKey) {
            $container->instance(KeyProviderInterface::class, MasterKey::fromHex(bin2hex(random_bytes(32))));
        }

        $manager = $this->configManager();
        $manager->repository()->set(new ResilienceConfig(
            backup: new BackupConfig(enabled: $enabled, trees: $trees),
        ));

        new BackupWiring()->wire(
            $container,
            $manager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        return $container;
    }

    /**
     * A loaded ConfigManager over a throwaway config directory: the repository is
     * only reachable after load(), and load() insists on the three mandatory files.
     */
    private function configManager(): ConfigManager
    {
        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_backup_' . bin2hex(random_bytes(4));
        mkdir($this->configPath, 0o755, true);

        foreach (['app.php', 'observability.php', 'security.php'] as $file) {
            file_put_contents($this->configPath . DIRECTORY_SEPARATOR . $file, '<?php return [];');
        }

        $manager = new ConfigManager($this->configPath);
        $manager->load();

        return $manager;
    }

    private static function removeTree(string $path): void
    {
        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($child)) {
                self::removeTree($child);

                continue;
            }

            if (is_file($child)) {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
