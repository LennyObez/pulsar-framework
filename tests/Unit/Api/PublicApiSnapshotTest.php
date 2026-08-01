<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Api\Internal;
use Pulsar\Tooling\Api\ApiSnapshotBuilder;
use ReflectionClass;

use function dirname;

/**
 * Verifies the committed API snapshot matches the current codebase.
 *
 * If this test fails, regenerate the snapshot:
 *   composer api:snapshot
 */
#[CoversClass(Api::class)]
final class PublicApiSnapshotTest extends TestCase
{
    private const string SNAPSHOT_PATH = __DIR__ . '/../../../tools/api/public-api.snapshot.json';

    #[Test]
    public function snapshotMatchesCurrentCodebase(): void
    {
        $committed = $this->loadCommittedSnapshot();
        $current = new ApiSnapshotBuilder(dirname(__DIR__, 3) . '/src')->build();

        self::assertSame(
            $committed,
            $current,
            "Public API snapshot is stale. Regenerate with: composer api:snapshot\n"
            . 'Then review the diff and commit the updated snapshot.',
        );
    }

    #[Test]
    public function allSnapshotApiClassesHaveApiAttribute(): void
    {
        $committed = $this->loadCommittedSnapshot();

        foreach (array_keys($committed['api_classes']) as $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                "Snapshot API class {$class} does not exist",
            );

            $ref = new ReflectionClass($class);
            self::assertNotEmpty(
                $ref->getAttributes(Api::class),
                "Snapshot API class {$class} must have #[Api] attribute",
            );
        }
    }

    #[Test]
    public function allSnapshotInternalClassesHaveInternalAttribute(): void
    {
        $committed = $this->loadCommittedSnapshot();

        foreach ($committed['internal_classes'] as $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                "Snapshot internal class {$class} does not exist",
            );

            $ref = new ReflectionClass($class);
            self::assertNotEmpty(
                $ref->getAttributes(Internal::class),
                "Snapshot internal class {$class} must have #[Internal] attribute",
            );
        }
    }

    /**
     * The snapshot must record inherited public methods, not just self-declared ones.
     *
     * A declaration-scoped snapshot cannot see a type losing its `extends`, and
     * never records the surface a type gains from a parent that is not itself
     * `#[Api]` — a vendor interface such as PSR-20's clock, for instance. Both are
     * breaking changes for consumers. This asserts the gate keeps watching them.
     */
    #[Test]
    public function snapshotRecordsInheritedPublicSurface(): void
    {
        $committed = $this->loadCommittedSnapshot();
        $clock = $committed['api_classes']['Pulsar\Testing\Clock\ClockInterface'] ?? null;

        self::assertIsArray($clock, 'ClockInterface must be in the API snapshot');
        self::assertArrayHasKey(
            'now',
            $clock['signatures'],
            'now() is callable on ClockInterface through PSR-20 and must be gated',
        );
        self::assertSame(
            'Psr\Clock\ClockInterface',
            $clock['signatures']['now']['inherited_from'] ?? null,
            'now() is inherited from PSR-20 and the snapshot must say so',
        );
        self::assertArrayNotHasKey(
            'inherited_from',
            $clock['signatures']['timestamp'],
            'timestamp() is declared here, so it must not be marked inherited',
        );
    }

    /**
     * PHP's own frozen methods stay out: they cannot break and would bury the diff.
     */
    #[Test]
    public function snapshotOmitsMethodsDeclaredByPhpItself(): void
    {
        $committed = $this->loadCommittedSnapshot();
        $engineDeclared = [];

        foreach ($committed['api_classes'] as $class => $entry) {
            foreach ($entry['signatures'] as $method => $signature) {
                $from = $signature['inherited_from'] ?? null;

                if ($from === null) {
                    continue;
                }

                // Also guards the snapshot against naming a declaring type that
                // no longer exists, and narrows the JSON string to a class-string.
                if (!class_exists($from) && !interface_exists($from)) {
                    self::fail("Snapshot names {$from} as a declaring type, but it does not exist");
                }

                if (new ReflectionClass($from)->isInternal()) {
                    $engineDeclared[] = "{$class}::{$method} <- {$from}";
                }
            }
        }

        self::assertSame([], $engineDeclared, 'Snapshot must not record engine-declared methods');
    }

    /**
     * @return array{api_classes: array<string, array{since: string, methods: list<string>, signatures: array<string, array{params: list<string>, return: string|null, static: bool, inherited_from?: string}>, constants: list<string>}>, internal_classes: list<string>}
     */
    private function loadCommittedSnapshot(): array
    {
        self::assertFileExists(self::SNAPSHOT_PATH, 'Snapshot file missing. Run: composer api:snapshot');

        $json = file_get_contents(self::SNAPSHOT_PATH);
        self::assertIsString($json);

        /** @var array{api_classes: array<string, array{since: string, methods: list<string>, signatures: array<string, array{params: list<string>, return: string|null, static: bool, inherited_from?: string}>, constants: list<string>}>, internal_classes: list<string>} */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
