<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\Internal\SandboxReach;
use Pulsar\Extensibility\Internal\SandboxReachAnalyzer;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Routing\RouterInterface;

use function class_exists;
use function interface_exists;
use function sprintf;

/**
 * Holds the safe-list to the claim its name makes.
 *
 * {@see ServiceRestrictionMap} classifies by service ID, and an ID is chosen by
 * the composition root: `Pulsar\Container\ContainerInterface` sat on the SAFE
 * list while the Kernel bound it to the real container, so a Community
 * extension reached everything the sandbox existed to withhold in two calls.
 * Nothing in the code said that was wrong, because "safe" was an assertion
 * about a string.
 *
 * This makes it an assertion about a TYPE, and checks it. An entry earns its
 * place by having no public method or property that leads — transitively — to
 * anything that dispenses services. Adding an ID whose type does not clear that
 * bar fails here rather than shipping, and so does adding a `->container()`
 * accessor to a type that is already listed.
 *
 * The constructor-reachability half of this suite is gone with the machinery it
 * covered. `SandboxReachAnalyzer::constructorReach()` predicted what the
 * container would autowire into a class an extension named; it gave up after
 * four hops and returned early for a class that did not exist yet, and both
 * were escaped. Prediction is replaced by construction — see
 * `ScopedContainerProxy::construct()` and `SandboxSurfaceTest`, which exercise
 * the thing that now happens instead of the thing that used to be guessed.
 */
#[CoversClass(SandboxReachAnalyzer::class)]
#[CoversClass(SandboxReach::class)]
final class SandboxReachAnalyzerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function safeServices(): iterable
    {
        foreach (ServiceRestrictionMap::defaults()->safeServices() as $serviceId) {
            yield $serviceId => [$serviceId];
        }
    }

    /**
     * The existence assertion is not decoration.
     *
     * `surfaceReach()` answers "no path found" for a type it cannot load, which
     * is the honest answer to the question asked and a VACUOUS pass for the
     * question this test means to ask. The safe list carried
     * `Pulsar\Observability\Metrics\MetricRegistryInterface`, a type that has
     * never existed in this framework, and it sailed through this check for
     * exactly that reason. `ServiceRestrictionMapTest` now checks every id in
     * both lists; this checks it again here, where the vacuum was.
     */
    #[Test]
    #[DataProvider('safeServices')]
    public function everySafeListedTypeIsProvablyInert(string $serviceId): void
    {
        self::assertTrue(
            class_exists($serviceId) || interface_exists($serviceId),
            sprintf(
                'Service "%s" is on the safe allowlist but names no class or interface. '
                . 'This check cannot say anything about a type it cannot load, so the entry '
                . 'would pass vacuously — which is how a phantom entry stayed on this list.',
                $serviceId,
            ),
        );

        $reachPath = SandboxReachAnalyzer::surfaceReach($serviceId);

        self::assertNull(
            $reachPath,
            sprintf(
                'Service "%s" is on the safe allowlist, so any extension with ContainerRead '
                . 'can resolve it at any tier — but its public surface reaches a service '
                . 'dispenser: %s. Either the type must stop exposing that, or the ID must '
                . 'come off the safe list and be classified as restricted.',
                $serviceId,
                $reachPath ?? '',
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function serviceDispensers(): iterable
    {
        yield 'pulsar container' => [ContainerInterface::class];
        yield 'psr container' => ['Psr\Container\ContainerInterface'];
        yield 'router' => [RouterInterface::class];
        yield 'kernel' => ['Pulsar\Core\Kernel'];
        yield 'extension bootstrap' => ['Pulsar\Extensibility\ExtensionBootstrap'];
        yield 'extension registry' => ['Pulsar\Extensibility\ExtensionRegistry'];
        yield 'config repository' => ['Pulsar\Config\ConfigRepository'];
    }

    /**
     * The check has to be able to fail, or its passing means nothing.
     */
    #[Test]
    #[DataProvider('serviceDispensers')]
    public function aServiceDispenserIsReportedAsReachable(string $type): void
    {
        self::assertNotNull(
            SandboxReachAnalyzer::surfaceReach($type),
            sprintf('"%s" hands out other services and must not be judged inert', $type),
        );
    }

    /**
     * The walk is unbounded now, and terminates on the visited set instead.
     *
     * The old bound was four hops, shared with the constructor walk, and one
     * more collaborator in the chain put the container outside it. Nothing here
     * needs a bound: every type is expanded at most once, so a cycle ends and a
     * long chain is followed to the end.
     */
    #[Test]
    public function reachIsFollowedPastTheDepthTheOldBoundStoppedAt(): void
    {
        $reach = SandboxReachAnalyzer::surfaceReach(SurfaceHopOne::class);

        self::assertNotNull($reach, 'a container six hops away is still a container');
        self::assertStringContainsString(ContainerInterface::class, $reach);
    }

    #[Test]
    public function aCyclicSurfaceTerminates(): void
    {
        self::assertNull(SandboxReachAnalyzer::surfaceReach(SurfaceCycleA::class));
    }

    #[Test]
    public function anOrdinarySurfaceIsNotReported(): void
    {
        self::assertNull(SandboxReachAnalyzer::surfaceReach(HasOnlyALogger::class));
    }

    #[Test]
    public function aTypeThatDoesNotExistIsNotClaimedToReachAnything(): void
    {
        self::assertNull(SandboxReachAnalyzer::surfaceReach('Acme\Absent\Service'));
    }

    /**
     * The type's OWN identity counts, unlike in the constructor question that
     * used to live beside this one.
     */
    #[Test]
    public function aTypeThatIsItselfADispenserIsReported(): void
    {
        self::assertNotNull(SandboxReachAnalyzer::surfaceReach(IsItselfADispenser::class));
    }
}

final class HasOnlyALogger
{
    public function __construct(public LoggerInterface $logger) {}
}

final class SurfaceHopOne
{
    public function next(): SurfaceHopTwo
    {
        return new SurfaceHopTwo();
    }
}

final class SurfaceHopTwo
{
    public function next(): SurfaceHopThree
    {
        return new SurfaceHopThree();
    }
}

final class SurfaceHopThree
{
    public function next(): SurfaceHopFour
    {
        return new SurfaceHopFour();
    }
}

final class SurfaceHopFour
{
    public function next(): SurfaceHopFive
    {
        return new SurfaceHopFive();
    }
}

final class SurfaceHopFive
{
    public function next(): SurfaceHopSix
    {
        return new SurfaceHopSix();
    }
}

final class SurfaceHopSix
{
    public ?ContainerInterface $container = null;
}

/**
 * Stands in for a service provider: the type itself is reach-capable, while
 * nothing reach-capable is injected into it.
 */
final class IsItselfADispenser implements PsrContainerInterface
{
    public function __construct(public string $name = '') {}

    public function get(string $id): mixed
    {
        return null;
    }

    public function has(string $id): bool
    {
        return false;
    }
}

final class SurfaceCycleA
{
    public ?SurfaceCycleB $b = null;
}

final class SurfaceCycleB
{
    public ?SurfaceCycleA $a = null;
}
