<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Pulsar\Compliance\Evidence\EvidenceStoreInterface;
use Pulsar\Compliance\Evidence\InMemoryEvidenceStore;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\ComplianceVerificationEngine;
use Pulsar\Compliance\Verification\CustomControl;
use Pulsar\Compliance\Verification\CustomControlRegistry;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\EvidenceChainVerdict;
use Pulsar\Compliance\Verification\EvidenceCollectionJob;
use Pulsar\Compliance\Verification\VerificationReport;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ComplianceVerificationWiring;
use Pulsar\Core\Wiring\ComplianceWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Security\Crypto\MasterKey;
use Stringable;

use function array_map;
use function bin2hex;
use function file_put_contents;
use function implode;
use function in_array;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_contains;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The three seams the verification wiring used to construct empty, and the
 * interval no scheduler read.
 */
#[CoversClass(ComplianceVerificationWiring::class)]
final class ComplianceVerificationWiringEvidenceTest extends TestCase
{
    private string $configPath = '';

    protected function tearDown(): void
    {
        if ($this->configPath !== '' && is_dir($this->configPath)) {
            $this->cleanDir($this->configPath);
        }
    }

    #[Test]
    public function anOnDemandVerificationRunLeavesASignedEvidenceRecord(): void
    {
        // Before the chain was passed to the engine, recordEvidence() returned at
        // its first line on every run and the evidence trail was the empty set.
        $store = new InMemoryEvidenceStore();
        $container = $this->boot(store: $store);

        self::assertTrue($container->has(EvidenceChain::class), 'the chain must be reachable');

        /** @var ComplianceVerificationEngine $engine */
        $engine = $container->get(ComplianceVerificationEngine::class);
        (void) $engine->verify();

        $records = $store->forControl(EvidenceChain::CONTROL_ID);

        self::assertCount(1, $records);
        self::assertNotNull($records[0]->signature);
    }

    #[Test]
    public function theRecordedChainVerifiesAsIntact(): void
    {
        $store = new InMemoryEvidenceStore();
        $container = $this->boot(store: $store);

        /** @var ComplianceVerificationEngine $engine */
        $engine = $container->get(ComplianceVerificationEngine::class);
        (void) $engine->verify();
        (void) $engine->verify();

        /** @var EvidenceChain $chain */
        $chain = $container->get(EvidenceChain::class);
        $integrity = $chain->verify();

        self::assertSame(
            EvidenceChainVerdict::Intact,
            $integrity->verdict,
            $integrity->summary . ' ' . implode(', ', $integrity->brokenAt),
        );
        self::assertSame(2, $integrity->verified);
        self::assertSame(2, $integrity->attested, 'the wired store keeps the chain anchored');
    }

    #[Test]
    public function theBootCheckDoesNotRecordEvidence(): void
    {
        // Under PHP-FPM the kernel boots once per REQUEST. A boot check that
        // recorded would append one signed record per request, which is not an
        // evidence trail.
        $store = new InMemoryEvidenceStore();
        $container = $this->boot(store: $store);

        self::assertTrue($container->has(VerificationReport::class), 'the boot check must still run');
        self::assertSame([], $store->forControl(EvidenceChain::CONTROL_ID));
    }

    #[Test]
    public function withoutAMasterKeyThereIsNoChainAndTheOperatorIsTold(): void
    {
        // A chain keyed on a constant would be a chain anybody can forge, so the
        // honest answer is no chain — said out loud.
        $spy = new EvidenceWarningSpy();
        $container = $this->boot(store: new InMemoryEvidenceStore(), masterKey: false, logger: $spy);

        self::assertFalse($container->has(EvidenceChain::class));
        self::assertTrue($spy->has('no compliance evidence chain'), $spy->dump());
    }

    #[Test]
    public function theEvidenceStoreIsAlwaysReachableEvenWithoutAKey(): void
    {
        $container = $this->boot(store: new InMemoryEvidenceStore(), masterKey: false);

        self::assertTrue($container->has(EvidenceStoreInterface::class));
    }

    #[Test]
    public function aCustomControlRegisteredBeforeBootIsVerified(): void
    {
        // The registry used to be `new CustomControlRegistry()` inside the wiring,
        // private and unreachable, so verifyAll() returned [] no matter what an
        // application did.
        $registry = new CustomControlRegistry();
        $registry->register(new CustomControl(
            id: 'acme.change_management',
            name: 'Change management',
            description: 'Every production change carries an approval ticket',
            verifier: static fn(): CheckResult => CheckResult::pass(
                'acme.change_management',
                'Approvals recorded for every change.',
                ComplianceCheckDomain::AccessControl,
            ),
        ));

        $container = $this->boot(store: new InMemoryEvidenceStore(), registry: $registry);

        /** @var VerificationReport $report */
        $report = $container->get(VerificationReport::class);

        self::assertTrue(
            in_array('acme.change_management', $this->checkIds($report), true),
            'a registered custom control must reach the report: ' . implode(', ', $this->checkIds($report)),
        );
    }

    #[Test]
    public function aCustomControlRegisteredAfterBootAffectsTheNextRun(): void
    {
        // The engine holds the container's registry instance, not a copy, so the
        // seam stays open after boot.
        $container = $this->boot(store: new InMemoryEvidenceStore());

        /** @var CustomControlRegistry $registry */
        $registry = $container->get(CustomControlRegistry::class);
        $registry->register(new CustomControl(
            id: 'acme.late_control',
            name: 'Late control',
            description: 'Registered after boot',
            verifier: static fn(): CheckResult => CheckResult::pass('acme.late_control', 'ok'),
        ));

        /** @var ComplianceVerificationEngine $engine */
        $engine = $container->get(ComplianceVerificationEngine::class);

        self::assertTrue(in_array('acme.late_control', $this->checkIds($engine->verify()), true));
    }

    #[Test]
    public function theEvidenceIntervalIsPutOnTheScheduler(): void
    {
        // `verification.evidence_interval` was a number in a config file that no
        // scheduler read.
        $registry = new JobRegistry();
        $this->boot(store: new InMemoryEvidenceStore(), jobs: $registry);

        self::assertTrue($registry->has(EvidenceCollectionJob::NAME));
        self::assertSame('0 * * * *', $registry->get(EvidenceCollectionJob::NAME)->getSchedule()->expression);
    }

    #[Test]
    public function aShorterIntervalProducesATighterSchedule(): void
    {
        $registry = new JobRegistry();
        $this->boot(
            store: new InMemoryEvidenceStore(),
            jobs: $registry,
            verification: "'evidence_interval' => 300",
        );

        self::assertSame('*/5 * * * *', $registry->get(EvidenceCollectionJob::NAME)->getSchedule()->expression);
    }

    #[Test]
    public function theProfilesPasswordMinimumAndBreachDeadlineReachTheReport(): void
    {
        // Two profile fields that no wiring, validator or reporter read.
        $container = $this->boot(store: new InMemoryEvidenceStore());

        /** @var VerificationReport $report */
        $report = $container->get(VerificationReport::class);
        $ids = $this->checkIds($report);

        self::assertTrue(in_array('auth.password_min_length', $ids, true), implode(', ', $ids));
        self::assertTrue(in_array('incident.breach_notification_deadline', $ids, true), implode(', ', $ids));
    }

    /**
     * @return list<string>
     */
    private function checkIds(VerificationReport $report): array
    {
        return array_map(static fn(CheckResult $r): string => $r->checkId, $report->results);
    }

    private function boot(
        EvidenceStoreInterface $store,
        bool $masterKey = true,
        ?LoggerInterface $logger = null,
        ?CustomControlRegistry $registry = null,
        ?JobRegistry $jobs = null,
        string $verification = "'evidence_interval' => 3600",
    ): Container {
        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_evidence_wiring_' . bin2hex(random_bytes(4));
        mkdir($this->configPath, 0o755, true);

        $this->write('app.php', '');
        $this->write('observability.php', '');
        $this->write('security.php', "'auth' => ['two_factor' => ['enabled' => true]]");
        $this->write('compliance.php', "'enabled_frameworks' => ['pci_dss'], 'verification' => [{$verification}]");

        $configManager = new ConfigManager($this->configPath);
        $complianceWiring = new ComplianceWiring();
        ConfigLoaderRegistrar::register($configManager, [$complianceWiring]);
        $configManager->load();

        $container = new Container();
        $container->instance(EvidenceStoreInterface::class, $store);

        if ($logger !== null) {
            $container->instance(LoggerInterface::class, $logger);
        }

        if ($masterKey) {
            $container->instance(MasterKey::class, MasterKey::fromHex(str_repeat('ab', 32)));
        }

        if ($jobs !== null) {
            $container->instance(JobRegistry::class, $jobs);
        }

        $pipeline = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();
        $router = new Router();

        $complianceWiring->wire($container, $configManager, $pipeline, $middlewareRegistry, $router);

        // Registered AFTER ComplianceWiring and BEFORE the verification wiring:
        // exactly the window an extension or an application boot hook occupies.
        if ($registry !== null) {
            $container->instance(CustomControlRegistry::class, $registry);
        }

        new ComplianceVerificationWiring()->wire($container, $configManager, $pipeline, $middlewareRegistry, $router);

        return $container;
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
final class EvidenceWarningSpy extends AbstractLogger
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
