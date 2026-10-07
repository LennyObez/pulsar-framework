<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\PHPStan\Rules\ForbidIntegrationEventBusInSagaStepRule;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

/**
 * The compensation-ordering rule, held against the code it forbids.
 *
 * A saga step that publishes on the integration event bus emits its event
 * immediately, outside the transaction that records the step's own state. When the
 * saga later fails and compensates, the event has already left: consumers have acted
 * on a step that is being undone, and no compensation can reach them. OutboxPort
 * exists so the event and the state commit together.
 *
 * The rule that forbids it had no test of any kind. It could have been reporting
 * nothing — a typo in the forbidden FQCN, a namespace pattern that no longer matches
 * the layout — and `composer phpstan` would have looked exactly the same.
 *
 * {@see PhpStanGateTest} proves the rule is registered in the configuration CI runs
 * and fires there. This proves it fires on the shapes a real handler is written in,
 * and stays silent on the ones it must not claim: the two questions are different,
 * and a rule that reports every constructor parameter would pass the first test.
 *
 * @extends RuleTestCase<ForbidIntegrationEventBusInSagaStepRule>
 */
#[GuardsGate(gate: 'phpstan rule ForbidIntegrationEventBusInSagaStepRule', plants: 'a saga step naming IntegrationEventBusPort by promotion, plain parameter, nullable, union and fully qualified name, under each of the three saga namespace segments')]
final class ForbidIntegrationEventBusInSagaStepRuleTest extends RuleTestCase
{
    use PlantsDefectsForGates;

    private const string MESSAGE = 'Saga step handlers must use OutboxPort for integration events, '
        . 'not IntegrationEventBusPort directly. '
        . 'Direct injection of IntegrationEventBusPort is forbidden in saga step handlers '
        . 'to ensure atomic event emission via the transactional outbox pattern.';

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();

        parent::tearDown();
    }

    /**
     * Promotion, plain parameter, nullable and union: four ways to name the same
     * port, all of which a handler can be written with and none of which changes
     * what happens at runtime.
     */
    #[Test]
    public function itReportsEveryWayAStepCanNameTheForbiddenPort(): void
    {
        $file = $this->plantFile($this->plantTree('saga-rule'), 'ChargeCard.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace PulsarGateProbe\Saga\Step;

            use Pulsar\Saga\Port\IntegrationEventBusPort;
            use Pulsar\Saga\Port\OutboxPort;

            final class ChargeCard
            {
                public function __construct(private readonly IntegrationEventBusPort $promoted) {}

                public function withPlainParameter(IntegrationEventBusPort $bus): void {}

                public function withNullable(?IntegrationEventBusPort $bus): void {}

                public function withUnion(IntegrationEventBusPort|OutboxPort $bus): void {}

                public function withFullyQualified(\Pulsar\Saga\Port\IntegrationEventBusPort $bus): void {}
            }
            PHP);

        $this->analyse([$file], [
            [self::MESSAGE, 12],
            [self::MESSAGE, 14],
            [self::MESSAGE, 16],
            [self::MESSAGE, 18],
            [self::MESSAGE, 20],
        ]);
    }

    /**
     * The other direction. Without this, a rule that reported every parameter of
     * every class would satisfy the test above, and `composer phpstan` would be
     * unpassable for reasons nobody could act on.
     */
    #[Test]
    public function itLeavesTheOutboxPortAndNonSagaClassesAlone(): void
    {
        $tree = $this->plantTree('saga-rule-clean');

        $correct = $this->plantFile($tree, 'Compliant.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace PulsarGateProbe\Saga\Step;

            use Pulsar\Saga\Port\OutboxPort;

            final class Compliant
            {
                public function __construct(private readonly OutboxPort $outbox) {}
            }
            PHP);

        // Outside a saga namespace the port is the right dependency: publishing an
        // integration event from a request handler is what the bus is for.
        $elsewhere = $this->plantFile($tree, 'Publisher.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace PulsarGateProbe\Messaging;

            use Pulsar\Saga\Port\IntegrationEventBusPort;

            final class Publisher
            {
                public function __construct(private readonly IntegrationEventBusPort $bus) {}
            }
            PHP);

        $this->analyse([$correct, $elsewhere], []);
    }

    /**
     * The three namespace segments the rule matches on are a contract with the
     * layout, and the layout is what a refactor moves. All three, or a step under a
     * renamed one stops being a step as far as the rule is concerned.
     */
    #[Test]
    public function itMatchesEverySagaNamespaceSegmentTheLayoutUses(): void
    {
        $tree = $this->plantTree('saga-rule-namespaces');
        $expected = [];
        $files = [];

        foreach (['Step', 'Handler', 'Action'] as $segment) {
            $files[] = $this->plantFile($tree, $segment . '.php', <<<PHP
                <?php

                declare(strict_types=1);

                namespace PulsarGateProbe\\Saga\\{$segment};

                use Pulsar\\Saga\\Port\\IntegrationEventBusPort;

                final class Refund{$segment}
                {
                    public function __construct(private readonly IntegrationEventBusPort \$bus) {}
                }
                PHP);
            $expected[] = [self::MESSAGE, 11];
        }

        foreach ($files as $index => $file) {
            $this->analyse([$file], [$expected[$index]]);
        }
    }

    protected function getRule(): Rule
    {
        return new ForbidIntegrationEventBusInSagaStepRule();
    }
}
