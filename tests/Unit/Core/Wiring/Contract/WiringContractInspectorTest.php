<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Core\Wiring\CacheWiring;
use Pulsar\Core\Wiring\Contract\DegradedFeature;
use Pulsar\Core\Wiring\Contract\OptionalBinding;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Core\Wiring\Contract\WiringContractInspector;

use function in_array;

#[CoversClass(WiringContractInspector::class)]
#[CoversClass(WiringContract::class)]
#[CoversClass(OptionalBinding::class)]
#[CoversClass(DegradedFeature::class)]
final class WiringContractInspectorTest extends TestCase
{
    /**
     * @param list<string> $bound
     */
    private function inspector(array $bound): WiringContractInspector
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => in_array($id, $bound, true),
        );

        return new WiringContractInspector($container);
    }

    /**
     * The exact regression that shipped silently: with TaggedCacheInterface
     * unbound, the anti-spam wiring's optional binding must surface as a
     * DEGRADED security feature carrying the fix — the signal nothing emitted
     * when CacheWiring failed to bind it.
     */
    #[Test]
    public function flagsTheUnboundTaggedCacheAsADegradedSecurityFeature(): void
    {
        $degraded = $this->inspector([])->degradedFeatures([new AntiSpamWiring()->describeWiring()]);

        $taggedCache = null;
        foreach ($degraded as $feature) {
            if ($feature->missingBinding === TaggedCacheInterface::class) {
                $taggedCache = $feature;
            }
        }

        self::assertNotNull($taggedCache, 'Unbound TaggedCacheInterface must be reported as degraded');
        self::assertSame('anti-spam', $taggedCache->component);
        self::assertTrue($taggedCache->security);
        self::assertStringContainsString('single-use', $taggedCache->feature);
        self::assertStringContainsString('cache', $taggedCache->fix);
        self::assertStringContainsString('DISABLED', $taggedCache->describe());
    }

    #[Test]
    public function reportsNoDegradationWhenOptionalBindingsAreSatisfied(): void
    {
        $bound = [TaggedCacheInterface::class, \Pulsar\Security\Crypto\MasterKey::class];

        $degraded = $this->inspector($bound)->degradedFeatures([new AntiSpamWiring()->describeWiring()]);

        self::assertSame([], $degraded);
    }

    #[Test]
    public function cacheWiringProvidesTaggedCacheSoAntiSpamRequirementIsSatisfiableInTheGraph(): void
    {
        // The full-boot graph: CacheWiring provides TaggedCacheInterface, which
        // is exactly what anti-spam optionally needs — no unmet requirements.
        $contracts = [
            new CacheWiring()->describeWiring(),
            new AntiSpamWiring()->describeWiring(),
        ];

        self::assertContains(TaggedCacheInterface::class, $contracts[0]->provides);
        self::assertSame([], $this->inspector([])->unsatisfiedRequirements($contracts));
    }

    #[Test]
    public function flagsARequiredBindingNoWiringProvides(): void
    {
        // A component that hard-requires a binding nothing in the graph provides
        // (and that is unbound in the container) is an intra-framework gap that
        // must be caught. LoggerInterface stands in as an unprovided requirement.
        $contract = new WiringContract(
            component: 'demo',
            requires: [LoggerInterface::class],
        );

        $unsatisfied = $this->inspector([])->unsatisfiedRequirements([$contract]);

        self::assertCount(1, $unsatisfied);
        self::assertSame('demo', $unsatisfied[0]['component']);
        self::assertSame(LoggerInterface::class, $unsatisfied[0]['binding']);
    }
}
