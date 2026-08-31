<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\Evidence\ControlEvidenceGatherer;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ComplianceCatalogWiring;
use Pulsar\Core\Wiring\WiringList;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\DatabaseTokenStore;
use ReflectionClass;

use function bin2hex;
use function file_put_contents;
use function in_array;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * The composition root's compliance step, and the one property it must never lose.
 */
#[CoversClass(ComplianceCatalogWiring::class)]
final class ComplianceCatalogWiringTest extends TestCase
{
    private const string TOKEN_STORE = 'Pulsar\Security\Crypto\TokenStoreInterface';

    /**
     * Bound at boot, built at first read.
     *
     * Boot binds three closures and resolves none of them. The catalog holds one
     * deferred source; the sixteen mapping classes behind it are not autoloaded
     * and not one of the 215 declarations exists until something reads the
     * catalog, which only `compliance:report` and the `compliance:check` gate do.
     * Eager construction cost 0.52 ms warm and roughly 22 ms cold on every boot
     * of every application for a structure no request looks at.
     *
     * That nothing is LOADED is asserted where the question has a meaningful
     * answer — a fresh process, in
     * {@see \Pulsar\Tests\Integration\Core\ComplianceCatalogLazinessTest}. By
     * the time this unit test runs, other tests have long since loaded every
     * mapping there is.
     */
    #[Test]
    public function theCatalogIsBoundUnresolvedAndHoldsEveryDeclaredControlOnceRead(): void
    {
        $container = $this->wire();

        self::assertTrue($container->has(ControlCatalog::class));
        self::assertTrue($container->has(ControlAssessment::class));

        self::assertNotContains(
            ControlCatalog::class,
            $container->getInstances(),
            'The catalog was resolved during wiring, so its declarations were built at boot.',
        );
        self::assertNotContains(ControlAssessment::class, $container->getInstances());

        /** @var ControlCatalog $catalog */
        $catalog = $container->get(ControlCatalog::class);

        // 193 before AiActMapping, which declares 22.
        self::assertSame(215, $catalog->count());
        self::assertNotNull($catalog->get(ComplianceFramework::PciDss, 'Req3.4'));
    }

    /**
     * One catalog per container, however many names reach it: two catalogs would
     * mean an extension's contribution landed in one of them and the report read
     * the other.
     */
    #[Test]
    public function theCatalogIsASingletonSharedWithTheAssessmentThatReadsIt(): void
    {
        $container = $this->wire();

        /** @var ControlCatalog $first */
        $first = $container->get(ControlCatalog::class);
        /** @var ControlCatalog $second */
        $second = $container->get(ControlCatalog::class);

        self::assertSame($first, $second);

        // The assessment must read that same catalog and not a second one built
        // from the same source: an extension's contribution would otherwise be
        // visible through one of them and absent from the other, and the report
        // would depend on which name the caller happened to resolve.
        /** @var ControlAssessment $assessment */
        $assessment = $container->get(ControlAssessment::class);

        self::assertSame($assessment, $container->get(ControlAssessment::class));

        $property = new ReflectionClass($assessment)->getProperty('catalog');

        self::assertSame($first, $property->getValue($assessment));
    }

    /**
     * Building the catalog on first read is safe precisely because a declaration
     * carries no outcome. Nothing in it is a claim about the deployment.
     */
    #[Test]
    public function nothingInTheCatalogAssertsAnythingAboutTheDeployment(): void
    {
        /** @var ControlCatalog $catalog */
        $catalog = $this->wire()->get(ControlCatalog::class);

        foreach ($catalog->all() as $declaration) {
            $properties = new ReflectionClass($declaration)->getProperties();
            $names = [];

            foreach ($properties as $property) {
                $names[] = $property->getName();
            }

            self::assertFalse(in_array('status', $names, true));
            self::assertFalse(in_array('outcome', $names, true));
        }
    }

    /**
     * The evidence gatherer is registered and NOT built.
     */
    #[Test]
    public function theGathererIsRegisteredWithoutBeingResolved(): void
    {
        $container = $this->wire();

        self::assertTrue($container->has(ControlEvidenceGatherer::class));
        self::assertNotContains(ControlEvidenceGatherer::class, $container->getInstances());
    }

    /**
     * The load-bearing regression test for the whole wiring.
     *
     * ADR-0041's defect was an ordering one: SecurityWiring is eighth in
     * {@see WiringList} and DatabaseWiring seventeenth, so anything that resolves
     * TokenStoreInterface during wiring gets the in-memory store and — because the
     * container caches singletons — keeps that answer for the life of the process.
     *
     * Here the token store is bound AFTER the compliance wiring has run, exactly as
     * a later wiring would bind it. The gathered evidence must name it. If the
     * gatherer had observed the deployment eagerly, this would report a vault the
     * running application does not use — truthfully, and chain-signed into the
     * evidence record, which is worse than not reporting at all.
     */
    #[Test]
    public function theGathererObservesTheDeploymentAsItIsWhenTheReportRunsNotAtBoot(): void
    {
        $container = $this->wire();

        // A later wiring binds the real vault, after compliance was wired.
        $container->instance(
            self::TOKEN_STORE,
            new ReflectionClass(DatabaseTokenStore::class)->newInstanceWithoutConstructor(),
        );

        /** @var ControlEvidenceGatherer $gatherer */
        $gatherer = $container->get(ControlEvidenceGatherer::class);
        $observation = $gatherer->gather()->observation(ObservationId::TokenVaultPersistence);

        self::assertTrue(
            $observation->present,
            'The gatherer observed the container as it was at boot, not as it is at report time.',
        );
        self::assertStringContainsString(DatabaseTokenStore::class, $observation->detail);
    }

    /**
     * One deployment, observed once. Gathering opens a database session, executes
     * every registered health check and recomputes an HMAC per stored evidence
     * record; two probes reaching different conclusions from differently-timed
     * measurements would be indefensible in an assessment.
     */
    #[Test]
    public function theGathererIsASingletonSoOneRunObservesTheDeploymentOnce(): void
    {
        $container = $this->wire();

        self::assertSame(
            $container->get(ControlEvidenceGatherer::class),
            $container->get(ControlEvidenceGatherer::class),
        );
    }

    #[Test]
    public function theWiringIsRegisteredLastInTheBootOrder(): void
    {
        $last = null;

        foreach (WiringList::default() as $wiring) {
            $last = $wiring;
        }

        self::assertInstanceOf(ComplianceCatalogWiring::class, $last);
    }

    private function wire(): Container
    {
        $container = new Container();
        $container->instance(
            'Pulsar\Compliance\ComplianceProfile',
            new ComplianceProfileResolver()->resolve([ComplianceFramework::PciDss]),
        );

        $configManager = new ConfigManager($this->configPath());
        $configManager->load();

        new ComplianceCatalogWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        return $container;
    }

    private function configPath(): string
    {
        $path = sys_get_temp_dir() . '/pulsar_catalog_wiring_' . bin2hex(random_bytes(6));
        @mkdir($path, 0o755, true);

        file_put_contents(
            $path . '/app.php',
            '<?php return ["name" => "Test", "env" => "testing", "debug" => false, '
                . '"timezone" => "UTC", "locale" => "en"];',
        );
        file_put_contents(
            $path . '/observability.php',
            '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];',
        );
        file_put_contents(
            $path . '/security.php',
            '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];',
        );

        return $path;
    }
}
