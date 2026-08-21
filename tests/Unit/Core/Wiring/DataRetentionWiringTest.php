<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\SchedulerConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\DataRetentionWiring;
use Pulsar\Core\Wiring\SchedulerWiring;
use Pulsar\Core\Wiring\SecurityWiring;
use Pulsar\Core\Wiring\ServiceWiringInterface;
use Pulsar\Core\Wiring\WiringList;
use Pulsar\DataProtection\DataProtectionConfig;
use Pulsar\DataProtection\DataPurgeJob;
use Pulsar\DataProtection\DataPurgeOrchestrator;
use Pulsar\DataProtection\DefaultRetentionPolicy;
use Pulsar\DataProtection\PurgeConfig;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\Schedule;
use Pulsar\Tests\Unit\DataProtection\Support\RecordingPurger;

use function array_map;
use function array_search;
use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(DataRetentionWiring::class)]
final class DataRetentionWiringTest extends TestCase
{
    private string $configPath = '';

    protected function tearDown(): void
    {
        if ($this->configPath !== '' && is_dir($this->configPath)) {
            $this->cleanDir($this->configPath);
        }
    }

    #[Test]
    public function registersTheRetentionJobSoDeclaredPoliciesAreActuallyRun(): void
    {
        // The defect: config/data_protection.php declared retention, SecurityWiring
        // built an orchestrator from it, and no scheduler or command ever ran it.
        [$container] = $this->wire(new RecordingPurger([]));

        /** @var JobRegistry $registry */
        $registry = $container->get(JobRegistry::class);

        self::assertTrue($registry->has(DataPurgeJob::NAME));
    }

    #[Test]
    public function theRegisteredJobRemovesExpiredRecordsWhenItRuns(): void
    {
        // Registration alone would be the same defect one level up. What matters
        // is that running the registered job deletes.
        $purger = new RecordingPurger(['session-a', 'session-b']);
        [$container] = $this->wire($purger);

        /** @var JobRegistry $registry */
        $registry = $container->get(JobRegistry::class);
        $registry->get(DataPurgeJob::NAME)->execute(
            new JobContext(new DateTimeImmutable(), new DateTimeImmutable()),
        );

        self::assertSame(['session-a', 'session-b'], $purger->purged);
        self::assertSame([], $purger->remaining());
    }

    #[Test]
    public function usesTheCronExpressionTheOperatorConfigured(): void
    {
        [$container] = $this->wire(new RecordingPurger([]), schedule: '17 4 * * 1');

        /** @var JobRegistry $registry */
        $registry = $container->get(JobRegistry::class);

        self::assertSame('17 4 * * 1', $registry->get(DataPurgeJob::NAME)->getSchedule()->expression);
    }

    #[Test]
    public function anEmptyScheduleIsAnExplicitOptOut(): void
    {
        // Keeping the policies without automatic deletion has to be sayable, and
        // it has to be said in a diff someone can read rather than by omission.
        [$container] = $this->wire(new RecordingPurger([]), schedule: '');

        /** @var JobRegistry $registry */
        $registry = $container->get(JobRegistry::class);

        self::assertFalse($registry->has(DataPurgeJob::NAME));
    }

    #[Test]
    public function takesTheSchedulerTimezoneSoItRunsAtTheHourEveryOtherJobMeans(): void
    {
        [$container] = $this->wire(new RecordingPurger([]), timezone: 'Europe/Brussels');

        /** @var JobRegistry $registry */
        $registry = $container->get(JobRegistry::class);

        self::assertSame('Europe/Brussels', $registry->get(DataPurgeJob::NAME)->getSchedule()->timezone);
    }

    #[Test]
    public function isInertWithoutAScheduler(): void
    {
        $container = new Container();
        $container->instance(DataPurgeOrchestrator::class, $this->orchestrator(new RecordingPurger([])));

        $this->wireInto($container, $this->configManager());

        self::assertFalse($container->has(JobRegistry::class));
    }

    #[Test]
    public function isInertWithoutAnOrchestrator(): void
    {
        $container = new Container();
        $registry = new JobRegistry();
        $container->instance(JobRegistry::class, $registry);

        $this->wireInto($container, $this->configManager());

        self::assertSame(0, $registry->count());
    }

    #[Test]
    public function leavesAnApplicationsOwnPurgeJobAlone(): void
    {
        // Overwriting an operator's job with the framework default would be the
        // framework deciding what gets deleted.
        $container = new Container();
        $registry = new JobRegistry();
        $ownJob = new DataPurgeJob($this->orchestrator(new RecordingPurger([])), new Schedule('0 9 * * *'));
        $registry->register($ownJob);

        $container->instance(JobRegistry::class, $registry);
        $container->instance(DataPurgeOrchestrator::class, $this->orchestrator(new RecordingPurger([])));

        $manager = $this->configManager();
        $manager->repository()->set(new DataProtectionConfig());

        $this->wireInto($container, $manager);

        self::assertSame('0 9 * * *', $registry->get(DataPurgeJob::NAME)->getSchedule()->expression);
    }

    #[Test]
    public function runsAfterTheSchedulerAndTheSecurityWiringItDependsOn(): void
    {
        $classes = array_map(
            static fn(ServiceWiringInterface $w): string => $w::class,
            WiringList::default(),
        );

        $security = array_search(SecurityWiring::class, $classes, true);
        $scheduler = array_search(SchedulerWiring::class, $classes, true);
        $retention = array_search(DataRetentionWiring::class, $classes, true);

        self::assertIsInt($security);
        self::assertIsInt($scheduler);
        self::assertIsInt($retention, 'DataRetentionWiring must be in the boot order');
        self::assertLessThan($retention, $scheduler, 'the job registry must exist first');
        self::assertLessThan($retention, $security, 'the purge orchestrator must exist first');
    }

    /**
     * @return array{0: Container, 1: ConfigManager}
     */
    private function wire(
        RecordingPurger $purger,
        string $schedule = PurgeConfig::DEFAULT_SCHEDULE,
        string $timezone = 'UTC',
    ): array {
        $container = new Container();
        $container->instance(JobRegistry::class, new JobRegistry());
        $container->instance(DataPurgeOrchestrator::class, $this->orchestrator($purger));
        $container->instance(SchedulerConfig::class, new SchedulerConfig(enabled: true, timezone: $timezone));

        $manager = $this->configManager();
        $manager->repository()->set(new DataProtectionConfig(
            retention: [],
            purge: new PurgeConfig(schedule: $schedule),
        ));

        $this->wireInto($container, $manager);

        return [$container, $manager];
    }

    /**
     * A loaded ConfigManager over a throwaway config directory: the repository is
     * only reachable after load(), and load() insists on the three mandatory files.
     */
    private function configManager(): ConfigManager
    {
        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_retention_' . bin2hex(random_bytes(4));
        mkdir($this->configPath, 0o755, true);

        foreach (['app.php', 'observability.php', 'security.php'] as $file) {
            file_put_contents($this->configPath . DIRECTORY_SEPARATOR . $file, '<?php return [];');
        }

        $manager = new ConfigManager($this->configPath);
        $manager->load();

        return $manager;
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

    private function wireInto(Container $container, ConfigManager $manager): void
    {
        new DataRetentionWiring()->wire(
            $container,
            $manager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );
    }

    private function orchestrator(RecordingPurger $purger): DataPurgeOrchestrator
    {
        return new DataPurgeOrchestrator(
            purgers: ['user_sessions' => $purger],
            policies: ['user_sessions' => new DefaultRetentionPolicy('user_sessions', 90)],
            config: new DataProtectionConfig(),
        );
    }
}
