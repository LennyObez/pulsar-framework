<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;

use function array_keys;
use function class_exists;
use function interface_exists;
use function sprintf;

#[CoversClass(ServiceRestrictionMap::class)]
final class ServiceRestrictionMapTest extends TestCase
{
    private ServiceRestrictionMap $map;

    protected function setUp(): void
    {
        $this->map = ServiceRestrictionMap::defaults();
    }

    #[Test]
    public function defaultsContainsRestrictedServices(): void
    {
        self::assertTrue($this->map->isRestricted('Pulsar\Security\Crypto\MasterKey'));
        self::assertTrue($this->map->isRestricted('Pulsar\Security\Crypto\KeyProviderInterface'));
    }

    #[Test]
    public function defaultsContainsSafeServices(): void
    {
        self::assertTrue($this->map->isSafe('Psr\Log\LoggerInterface'));
        self::assertTrue($this->map->isSafe('Psr\EventDispatcher\EventDispatcherInterface'));
    }

    #[Test]
    public function requiredCapabilityReturnsCapabilityForRestrictedService(): void
    {
        $capability = $this->map->requiredCapability('Pulsar\Security\Crypto\MasterKey');

        self::assertSame(ExtensionCapability::CryptoKeyAccess, $capability);
    }

    #[Test]
    public function requiredCapabilityReturnsNullForSafeService(): void
    {
        $capability = $this->map->requiredCapability('Psr\Log\LoggerInterface');

        self::assertNull($capability);
    }

    #[Test]
    public function isUnknownReturnsTrueForUnmappedService(): void
    {
        self::assertTrue($this->map->isUnknown('SomeRandom\Service'));
    }

    #[Test]
    public function isUnknownReturnsFalseForRestrictedService(): void
    {
        self::assertFalse($this->map->isUnknown('Pulsar\Security\Crypto\MasterKey'));
    }

    #[Test]
    public function isUnknownReturnsFalseForSafeService(): void
    {
        self::assertFalse($this->map->isUnknown('Psr\Log\LoggerInterface'));
    }

    #[Test]
    public function customMapWithExplicitServices(): void
    {
        $map = new ServiceRestrictionMap(
            restrictedServices: ['CustomSecret' => ExtensionCapability::CryptoKeyAccess],
            safeServices: ['CustomLogger'],
        );

        self::assertTrue($map->isRestricted('CustomSecret'));
        self::assertTrue($map->isSafe('CustomLogger'));
        self::assertTrue($map->isUnknown('Other'));
    }

    #[Test]
    public function databaseServicesRequireDatabaseRaw(): void
    {
        self::assertSame(
            ExtensionCapability::DatabaseRaw,
            $this->map->requiredCapability('Pulsar\Database\ConnectionInterface'),
        );
    }

    #[Test]
    public function auditSinkRequiresAuditSinkAccess(): void
    {
        self::assertSame(
            ExtensionCapability::AuditSinkAccess,
            $this->map->requiredCapability('Pulsar\Security\Audit\AuditSinkInterface'),
        );
    }

    /**
     * The audit LOGGER is priced too, and separately.
     *
     * It was on the safe list while `AuditWrite` was granted-but-never-checked,
     * so an Untrusted extension — which holds `ContainerRead` and explicitly not
     * `AuditWrite` — resolved the host's audit logger and wrote entries with it.
     * The sink and the logger are different powers and now cost different
     * capabilities.
     */
    #[Test]
    public function theAuditLoggerCostsAuditWriteRatherThanBeingSafeListed(): void
    {
        self::assertFalse($this->map->isSafe('Pulsar\Audit\AuditLoggerInterface'));
        self::assertSame(
            ExtensionCapability::AuditWrite,
            $this->map->requiredCapability('Pulsar\Audit\AuditLoggerInterface'),
        );
    }

    /**
     * `MiddlewareRegister` was declared, granted down to Verified, and consulted
     * nowhere: `SandboxReach` refused the pipeline and the registry outright, so
     * the tier table promised a power the code refused to the very tier it was
     * promised to. Pricing the two ids the Kernel binds is the enforcement site.
     */
    #[Test]
    public function globalMiddlewareCostsMiddlewareRegister(): void
    {
        self::assertSame(
            ExtensionCapability::MiddlewareRegister,
            $this->map->requiredCapability('Pulsar\Http\Middleware\MiddlewarePipelineInterface'),
        );
        self::assertSame(
            ExtensionCapability::MiddlewareRegister,
            $this->map->requiredCapability('Pulsar\Http\Middleware\MiddlewareRegistry'),
        );
    }

    /**
     * The framework's boot artifact store is not safe, and is not priced either.
     *
     * `FrameworkCacheInterface` holds the compiled route table, the container's
     * resolution hints and the compiled views — what the NEXT boot executes.
     * Safe-listing it meant any tier holding `ContainerRead`, Untrusted
     * included, could rewrite that. No capability in the model is about
     * rewriting the framework's own build output, so it falls to
     * deny-by-default rather than being given a price it does not have.
     */
    #[Test]
    public function theFrameworkCacheIsNeitherSafeNorPriced(): void
    {
        self::assertTrue($this->map->isUnknown('Pulsar\Cache\FrameworkCacheInterface'));
    }

    /**
     * Every id in either list must name a type that exists.
     *
     * Five did not: `Pulsar\Database\DatabaseManagerInterface`,
     * `Pulsar\Audit\AuditSinkInterface`, `Pulsar\Storage\FilesystemInterface`,
     * `Pulsar\Process\ProcessManagerInterface` and, on the safe list,
     * `Pulsar\Observability\Metrics\MetricRegistryInterface`.
     *
     * An entry for an absent type gates nothing. The id the framework actually
     * binds is unclassified, so it falls through to deny-by-default — which is
     * the same answer a Community extension would have got anyway, and the
     * WRONG answer for a Verified extension that was granted the capability and
     * still could not use the service. The phantom safe entry was worse: it made
     * `SandboxReachAnalyzerTest` pass VACUOUSLY, because a surface walk over a
     * class that cannot be loaded reports "reaches nothing".
     */
    #[Test]
    public function everyMappedIdNamesATypeThatExists(): void
    {
        $ids = [
            ...array_keys($this->map->restrictedServices()),
            ...$this->map->safeServices(),
        ];

        foreach ($ids as $id) {
            self::assertTrue(
                class_exists($id) || interface_exists($id),
                sprintf(
                    'Service id "%s" is classified in the restriction map but names no class or '
                    . 'interface, so it gates nothing: the id the framework actually binds falls '
                    . 'through to deny-by-default and the classification is invisible.',
                    $id,
                ),
            );
        }
    }

    /**
     * `ProcessExec` has no entry, and that is a statement rather than an
     * omission.
     *
     * The map used to price `Pulsar\Process\ProcessManagerInterface`, a type
     * that has never existed here. The framework exposes no process-execution
     * SERVICE at all — `ServeCommand` calls `proc_open()` directly — so there is
     * nothing to price. The capability is granted to Core alone, and Core
     * bypasses this map entirely, so the grant is inert either way; naming a
     * fictional service made it look otherwise.
     */
    #[Test]
    public function processExecPricesNothingBecauseThereIsNoProcessService(): void
    {
        self::assertNotContains(
            ExtensionCapability::ProcessExec,
            $this->map->restrictedServices(),
        );
    }
}
