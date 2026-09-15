<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\BcBreak;
use Pulsar\Api\BcBreakDetector;
use Pulsar\Api\BcBreakSeverity;
use Pulsar\Api\BcBreakType;

use function array_key_first;
use function count;
use function dirname;
use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The detector is driven by the committed snapshot, not by an invented shape.
 *
 * This file used to build its own two-level arrays with `stability` and
 * `methods.<name>.signature` keys. Nothing in the repository has ever produced
 * that document, so every test passed against a detector that returned an empty
 * list for every real input — the gate on the whole `#[Api]` promise, green
 * because it was blind.
 *
 * So the fixtures here start from `tools/api/public-api.snapshot.json` and a
 * break is planted in a copy of it. If the detector stops understanding the
 * shape the generator writes, these fail; the previous tests could not.
 */
#[CoversClass(BcBreakDetector::class)]
#[CoversClass(BcBreak::class)]
final class BcBreakDetectorTest extends TestCase
{
    private BcBreakDetector $detector;

    /** @var array{api_classes: array<string, array{since: string, stability?: string, methods: list<string>, signatures: array<string, array{params: list<string>, return: string|null, static: bool, inherited_from?: string}>, constants: list<string>}>, internal_classes: list<string>} */
    private array $snapshot;

    protected function setUp(): void
    {
        $this->detector = new BcBreakDetector();
        $this->snapshot = $this->loadSnapshot();
    }

    // ── Against the real committed snapshot ────────────────────────────

    #[Test]
    public function aSnapshotComparedWithItselfReportsNothing(): void
    {
        self::assertSame([], $this->detector->detect($this->snapshot, $this->snapshot));
    }

    /**
     * The shape check that matters: a real snapshot must yield real classes.
     *
     * Reading the top level as the map of types gave exactly two "classes",
     * `api_classes` and `internal_classes`, and dropping the whole document
     * produced two removals rather than three thousand.
     */
    #[Test]
    public function droppingEverythingReportsOneBreakPerStableType(): void
    {
        $breaks = $this->detector->detect($this->snapshot, ['api_classes' => [], 'internal_classes' => []]);

        self::assertCount(count($this->snapshot['api_classes']), $breaks);
        self::assertGreaterThan(1000, count($breaks), 'A real snapshot holds thousands of stable types');

        foreach ($breaks as $break) {
            self::assertSame(BcBreakType::ClassRemoved, $break->type);
        }
    }

    /**
     * Plant a real break: delete a stable type the framework really ships.
     */
    #[Test]
    public function removingAStableClassIsCaughtAndBlocks(): void
    {
        $victim = 'Pulsar\Http\Message\Response';
        self::assertArrayHasKey($victim, $this->snapshot['api_classes'], 'Fixture drifted: pick a type that exists');

        $current = $this->snapshot;
        unset($current['api_classes'][$victim]);

        $breaks = $this->detector->detect($this->snapshot, $current);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakType::ClassRemoved, $breaks[0]->type);
        self::assertSame($victim, $breaks[0]->symbol);
        self::assertSame(BcBreakSeverity::Error, $breaks[0]->severity);
        self::assertTrue($this->detector->hasBlockingBreaks($breaks));
    }

    /**
     * Plant a real break: delete a method from a stable type.
     */
    #[Test]
    public function removingAMethodFromAStableClassIsCaughtAndBlocks(): void
    {
        $victim = 'Pulsar\Http\Message\Response';
        self::assertArrayHasKey('json', $this->snapshot['api_classes'][$victim]['signatures']);

        $current = $this->snapshot;
        unset($current['api_classes'][$victim]['signatures']['json']);

        $breaks = $this->detector->detect($this->snapshot, $current);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakType::MethodRemoved, $breaks[0]->type);
        self::assertSame("{$victim}::json", $breaks[0]->symbol);
        self::assertTrue($this->detector->hasBlockingBreaks($breaks));
    }

    /**
     * Plant a real break: add a required parameter to a stable method.
     */
    #[Test]
    public function addingAParameterToAStableMethodIsCaughtAndBlocks(): void
    {
        $victim = 'Pulsar\Http\Message\Response';

        $current = $this->snapshot;
        $current['api_classes'][$victim]['signatures']['json']['params'][] = 'int $flags';

        $breaks = $this->detector->detect($this->snapshot, $current);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakType::SignatureChanged, $breaks[0]->type);
        self::assertStringContainsString('int $flags', $breaks[0]->message);
        self::assertTrue($this->detector->hasBlockingBreaks($breaks));
    }

    /**
     * A changed return type on an otherwise identical method is reported as
     * such, so the report says where to look.
     */
    #[Test]
    public function changingOnlyTheReturnTypeIsReportedAsAReturnTypeBreak(): void
    {
        $victim = 'Pulsar\Http\Message\Response';

        $current = $this->snapshot;
        $current['api_classes'][$victim]['signatures']['json']['return'] = 'string';

        $breaks = $this->detector->detect($this->snapshot, $current);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakType::ReturnTypeNarrowed, $breaks[0]->type);
    }

    /**
     * `static` is part of the contract: losing it changes how every caller has
     * to reach the method, and comparing parameters alone would miss it.
     */
    #[Test]
    public function turningAStaticMethodIntoAnInstanceMethodIsCaught(): void
    {
        $victim = 'Pulsar\Http\Message\Response';
        self::assertTrue($this->snapshot['api_classes'][$victim]['signatures']['json']['static']);

        $current = $this->snapshot;
        $current['api_classes'][$victim]['signatures']['json']['static'] = false;

        $breaks = $this->detector->detect($this->snapshot, $current);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakType::SignatureChanged, $breaks[0]->type);
    }

    /**
     * A trait's members reach the gate the same way, now that traits are in the
     * snapshot at all.
     */
    #[Test]
    public function removingAMethodFromAStableTraitIsCaught(): void
    {
        $victim = 'Pulsar\Testing\Database\RefreshDatabase';
        self::assertArrayHasKey($victim, $this->snapshot['api_classes'], 'Traits must be in the snapshot');

        $method = array_key_first($this->snapshot['api_classes'][$victim]['signatures']);
        self::assertIsString($method, 'The trait entry records no surface to break');

        $current = $this->snapshot;
        unset($current['api_classes'][$victim]['signatures'][$method]);

        $breaks = $this->detector->detect($this->snapshot, $current);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakType::MethodRemoved, $breaks[0]->type);
        self::assertSame("{$victim}::{$method}", $breaks[0]->symbol);
    }

    /**
     * Extension types are in the same package under the same attribute, so a
     * removal there blocks the same way it does in src/.
     */
    #[Test]
    public function removingAnExtensionApiClassIsCaughtAndBlocks(): void
    {
        $victim = null;

        foreach ($this->snapshot['api_classes'] as $class => $_) {
            if (str_starts_with($class, 'Pulsar\Extension\\')) {
                $victim = $class;

                break;
            }
        }

        self::assertIsString($victim, 'No extension type in the snapshot; extensions/ is outside the gate again');

        $current = $this->snapshot;
        unset($current['api_classes'][$victim]);

        $breaks = $this->detector->detect($this->snapshot, $current);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakSeverity::Error, $breaks[0]->severity);
        self::assertTrue($this->detector->hasBlockingBreaks($breaks));
    }

    /**
     * An `#[Api]`-marked constant is a promise too, and a removed one blocks.
     *
     * The constant is planted rather than borrowed: `#[Api]` targets class
     * constants and the builder collects them into every entry's `constants`
     * list, but nothing in the tree marks one today, so every list is empty. The
     * entry it is planted into is the real one, so this still exercises the real
     * shape — and the day a constant is marked, this needs no change.
     */
    #[Test]
    public function removingAnApiConstantIsCaught(): void
    {
        $class = 'Pulsar\Http\Message\Response';
        self::assertSame([], $this->snapshot['api_classes'][$class]['constants']);

        $previous = $this->snapshot;
        $previous['api_classes'][$class]['constants'] = ['STATUS_OK'];

        $breaks = $this->detector->detect($previous, $this->snapshot);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakType::ConstantRemoved, $breaks[0]->type);
        self::assertSame("{$class}::STATUS_OK", $breaks[0]->symbol);
        self::assertTrue($this->detector->hasBlockingBreaks($breaks));
    }

    // ── Additive change is never a break ───────────────────────────────

    #[Test]
    public function newClassesAndNewMethodsAreNotBreaks(): void
    {
        $current = $this->snapshot;
        $current['api_classes']['App\BrandNew'] = [
            'since' => '1.1.0',
            'methods' => [],
            'signatures' => ['fresh' => ['params' => [], 'return' => 'void', 'static' => false]],
            'constants' => [],
        ];
        $current['api_classes']['Pulsar\Http\Message\Response']['signatures']['brandNew'] = [
            'params' => [],
            'return' => 'static',
            'static' => true,
        ];

        self::assertSame([], $this->detector->detect($this->snapshot, $current));
    }

    // ── Severity follows the stability grade ───────────────────────────

    #[Test]
    public function experimentalBreaksWarnRatherThanBlock(): void
    {
        $victim = 'Pulsar\Http\Message\Response';

        $previous = $this->snapshot;
        $previous['api_classes'][$victim]['stability'] = 'experimental';

        $current = $previous;
        unset($current['api_classes'][$victim]);

        $breaks = $this->detector->detect($previous, $current);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakSeverity::Warning, $breaks[0]->severity);
        self::assertSame('experimental', $breaks[0]->stability);
        self::assertFalse($this->detector->hasBlockingBreaks($breaks));
    }

    /**
     * A missing `stability` key means stable, because that is what `#[Api]`
     * itself defaults to and what the builder omits.
     */
    #[Test]
    public function anEntryWithoutAStabilityKeyIsTreatedAsStable(): void
    {
        $victim = 'Pulsar\Http\Message\Response';
        self::assertArrayNotHasKey('stability', $this->snapshot['api_classes'][$victim]);

        $current = $this->snapshot;
        unset($current['api_classes'][$victim]);

        $breaks = $this->detector->detect($this->snapshot, $current);

        self::assertSame('stable', $breaks[0]->stability);
        self::assertSame(BcBreakSeverity::Error, $breaks[0]->severity);
    }

    #[Test]
    public function hasBlockingBreaksIsFalseForNothingAndForWarningsOnly(): void
    {
        self::assertFalse($this->detector->hasBlockingBreaks([]));

        $warningOnly = [
            new BcBreak(
                type: BcBreakType::ClassRemoved,
                symbol: 'X',
                message: 'X removed',
                severity: BcBreakSeverity::Warning,
                stability: 'experimental',
            ),
        ];

        self::assertFalse($this->detector->hasBlockingBreaks($warningOnly));
    }

    // ── Malformed input ────────────────────────────────────────────────

    /**
     * A document with no `api_classes` carries no stable surface, so there is
     * nothing to break — and, critically, its top-level keys are not types.
     */
    #[Test]
    public function aDocumentWithoutApiClassesYieldsNothing(): void
    {
        self::assertSame([], $this->detector->detect(['internal_classes' => ['A']], ['internal_classes' => []]));
        self::assertSame([], $this->detector->detect([], []));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * @return array{api_classes: array<string, array{since: string, stability?: string, methods: list<string>, signatures: array<string, array{params: list<string>, return: string|null, static: bool, inherited_from?: string}>, constants: list<string>}>, internal_classes: list<string>}
     */
    private function loadSnapshot(): array
    {
        $path = dirname(__DIR__, 3) . '/tools/api/public-api.snapshot.json';
        self::assertFileExists($path, 'Snapshot missing. Run: composer api:snapshot');

        $json = file_get_contents($path);
        self::assertIsString($json);

        /** @var array{api_classes: array<string, array{since: string, stability?: string, methods: list<string>, signatures: array<string, array{params: list<string>, return: string|null, static: bool, inherited_from?: string}>, constants: list<string>}>, internal_classes: list<string>} */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

}
