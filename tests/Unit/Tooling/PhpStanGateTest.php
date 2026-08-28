<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function file_get_contents;

/**
 * Plants what `composer phpstan` exists to refuse, and observes the refusal.
 *
 * The gate is `composer phpstan` (composer.json) run from `.github/workflows/ci.yml`,
 * and its whole product is an exit code. Nothing in the repository had ever watched
 * that exit code turn non-zero: every assertion about PHPStan was an assertion that
 * the healthy tree passes, which is the same observation you would make if the
 * `paths` list were empty, the level had been lowered to 5, or the two custom rules
 * had been dropped from `services:`.
 *
 * So the fixture tree below carries one defect per claim the gate makes:
 *
 *   level: max ......... two errors that exist only above level 5, held against the
 *                        configured level and against an explicit `--level=5`, so the
 *                        assertion fails if the level is ever quietly lowered.
 *   deprecation rules .. a call to an `@deprecated` method, which PHPStan does not
 *                        report at all unless phpstan-deprecation-rules is included.
 *   custom rules ....... a saga step handler injecting IntegrationEventBusPort, which
 *                        only ForbidIntegrationEventBusInSagaStepRule reports, and
 *                        only while it stays registered in the analysed config.
 *
 * The configuration file is the one CI names. A cut-down config written for the test
 * would prove that PHPStan works, which was never in doubt; what is in doubt is
 * whether the configuration CI runs still points at anything.
 */
#[GuardsGate(gate: 'composer phpstan', plants: 'an untyped iterable parameter and a method call on mixed (level max), a call to an @deprecated method, and a saga step injecting IntegrationEventBusPort')]
final class PhpStanGateTest extends TestCase
{
    use PlantsDefectsForGates;

    private const string CONFIG = 'tools/php/phpstan.neon';

    private const string LEVEL_MAX_ONLY = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace PulsarGateProbe;

        final class LevelMaxOnly
        {
            /**
             * @param array $items
             */
            public function total(array $items): int
            {
                return \count($items);
            }

            public function reach(mixed $value): string
            {
                return $value->label();
            }
        }
        PHP;

    private const string DEPRECATED_CALLER = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace PulsarGateProbe;

        final class Legacy
        {
            /**
             * @deprecated replaced by fresh()
             */
            public function stale(): void {}

            public function fresh(): void {}
        }

        final class DeprecatedCaller
        {
            public function run(Legacy $legacy): void
            {
                $legacy->stale();
            }
        }
        PHP;

    private const string SAGA_STEP = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace PulsarGateProbe\Saga\Step;

        use Pulsar\Saga\Port\IntegrationEventBusPort;

        final class ChargeCard
        {
            public function __construct(private readonly IntegrationEventBusPort $bus) {}

            public function bus(): IntegrationEventBusPort
            {
                return $this->bus;
            }
        }
        PHP;

    #[Test]
    public function itRefusesTheDefectsTheConfiguredAnalysisExistsToCatch(): void
    {
        $tree = $this->plantDefects();

        [$status, $stdout] = $this->analyse($tree);

        self::assertSame(
            1,
            $status,
            "composer phpstan accepted a tree containing an untyped iterable parameter, a method\n"
            . "call on mixed, a call to a deprecated method and a saga step injecting\n"
            . "IntegrationEventBusPort. Had it stayed silent, every one of those would ship: the\n"
            . "level-max findings say the analysis level is no longer max, the deprecation finding\n"
            . "says phpstan-deprecation-rules is no longer included, and the saga finding says the\n"
            . "compensation-ordering rule is no longer registered in the config CI runs.\n"
            . $stdout,
        );

        self::assertStringContainsString(
            'has parameter $items with no value type specified in iterable type array',
            $stdout,
            'level 6 reporting is gone: array parameters may now ship with no value type',
        );
        self::assertStringContainsString(
            'Cannot call method label() on mixed',
            $stdout,
            'level 9 reporting is gone: mixed may now be dereferenced without a check',
        );
        self::assertStringContainsString(
            'Call to deprecated method stale()',
            $stdout,
            'phpstan-deprecation-rules is no longer included: retiring APIs may now be called '
            . 'from anywhere without the build noticing',
        );
        self::assertStringContainsString(
            'Saga step handlers must use OutboxPort for integration events',
            $stdout,
            'ForbidIntegrationEventBusInSagaStepRule is no longer registered in tools/php/phpstan.neon: '
            . 'a saga step may now publish outside the transactional outbox, which breaks compensation '
            . 'ordering exactly when a saga fails',
        );
    }

    /**
     * The two findings above exist only above level 5, so the same file is clean
     * there. That is what makes them evidence about the level rather than about
     * PHPStan: if `level:` ever drops, the previous test stops failing and this one
     * keeps passing, and the pair no longer agrees.
     */
    #[Test]
    public function theLevelMaxFindingsAreAbsentBelowLevelMax(): void
    {
        $tree = $this->plantTree('phpstan-level');
        $file = $this->plantFile($tree, 'LevelMaxOnly.php', self::LEVEL_MAX_ONLY);

        [$status, $stdout] = $this->runGate([
            'vendor/bin/phpstan',
            'analyse',
            '--configuration=' . self::CONFIG,
            '--level=5',
            '--no-progress',
            '--error-format=raw',
            $file,
        ]);

        self::assertSame(
            0,
            $status,
            "the file used as level-max evidence is refused at level 5 too, so its refusal by\n"
            . "composer phpstan says nothing about the configured level and the test above proves\n"
            . "less than it claims.\n" . $stdout,
        );
    }

    /**
     * The gate is worth nothing if the config it uses analyses nothing.
     *
     * `paths:` is the one part of tools/php/phpstan.neon that a subprocess run
     * against an explicit path cannot exercise, because a path given on the command
     * line replaces it. Assert the trees are still listed instead.
     */
    #[Test]
    public function theConfiguredPathsStillCoverEveryFirstPartyTree(): void
    {
        $config = file_get_contents($this->repositoryRoot() . '/' . self::CONFIG);

        self::assertIsString($config);

        foreach (['../../src', '../../tests', '../../tools', '../../scripts', '../../benchmarks'] as $tree) {
            self::assertStringContainsString(
                "\n        - " . $tree . "\n",
                $config,
                $tree . ' has dropped out of phpstan.neon paths: everything under it would now be '
                . 'analysed by nothing, and the gate would stay green over it',
            );
        }
    }

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();
    }

    private function plantDefects(): string
    {
        $tree = $this->plantTree('phpstan');

        $this->plantFile($tree, 'LevelMaxOnly.php', self::LEVEL_MAX_ONLY);
        $this->plantFile($tree, 'DeprecatedCaller.php', self::DEPRECATED_CALLER);
        $this->plantFile($tree, 'Saga/Step/ChargeCard.php', self::SAGA_STEP);

        return $tree;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function analyse(string $path): array
    {
        return $this->runGate([
            '-d',
            'memory_limit=1G',
            'vendor/bin/phpstan',
            'analyse',
            '--configuration=' . self::CONFIG,
            '--no-progress',
            '--error-format=raw',
            $path,
        ]);
    }
}
