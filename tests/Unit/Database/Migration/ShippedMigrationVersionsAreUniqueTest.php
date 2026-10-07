<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Migration\MigrationPathResolver;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Routing\RouterInterface;

use function count;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function json_decode;
use function ksort;
use function realpath;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * No two migrations the framework or its bundled extensions ship carry the same version.
 *
 * ## What a shared version costs
 *
 * A version is the only thing the migrations table records about a migration's identity;
 * there is no column naming the source it came from. Two consequences follow, and both
 * were observed on this tree before this test existed, where `20260327000001` was shipped
 * by `src/Auth/Database/Migration`, `extensions/analytics/src/Migration` AND
 * `extensions/health-status/database/migrations` at once:
 *
 *   1. **Enable two of them together and nothing migrates at all.**
 *      {@see MigrationRepository::discover()} raises
 *      {@see DatabaseException::duplicateMigrationVersion()} on the second sighting, and
 *      every command that discovers — `migrate`, `migrate:status`, `migrate:rollback`,
 *      `db:fresh` — dies on it. Loud, and a total stop.
 *
 *   2. **Enable them one after the other and the second one silently never runs.**
 *      `20260327000001` recorded while analytics was installed makes health-status'
 *      unrelated `20260327000001` read as already applied:
 *      {@see \Pulsar\Database\Migration\MigrationRunner::getPending()} subtracts applied
 *      versions by key, so `health_check_history` is never created, `migrate` reports
 *      nothing pending and exits 0, and the first write to the missing table is where the
 *      operator finds out. That is the dangerous one, and it is why a duplicate is
 *      refused here rather than only where the two happen to meet at runtime.
 *
 * ## Why the check is the production discovery, not a regex over filenames
 *
 * A sequential filename (`001_create_pages.php`) is NOT its own version: it is qualified
 * by the name of the source that ships it, so CMS `001_` and Forum `001_` are distinct and
 * must not be reported here. That rule lives in {@see MigrationRepository::discover()} and
 * {@see \Pulsar\Database\Migration\MigrationVersionScheme}, and re-implementing it in a
 * test is how a test starts disagreeing with the runner it is supposed to guard. So the
 * versions compared below are the ones `pulsar migrate` itself derives, source by source.
 *
 * The directories are likewise resolved rather than listed: {@see MigrationPathResolver}
 * supplies the framework's own, and the extension entries come from each `pulsar.json` in
 * the tree, so an extension added tomorrow is covered on the run that adds it.
 */
final class ShippedMigrationVersionsAreUniqueTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /**
     * The number of shipped migrations below which this test is guarding nothing.
     *
     * A resolver that returned an empty map, or manifests that stopped declaring their
     * migration directories, would leave every assertion below trivially true. The bound
     * is far under the count the tree actually ships, so it fails on a collapse rather
     * than on the next migration anyone adds.
     */
    private const int MINIMUM_SHIPPED_MIGRATIONS = 60;

    /**
     * Every version, across every source, appears exactly once.
     */
    #[Test]
    public function noVersionIsShippedByTwoSources(): void
    {
        $byVersion = $this->versionsBySource();

        $duplicates = [];

        foreach ($byVersion as $version => $paths) {
            if (count($paths) > 1) {
                $duplicates[$version] = $paths;
            }
        }

        self::assertSame(
            [],
            $duplicates,
            "These migration versions are shipped by more than one source:\n"
            . $this->describe($duplicates)
            . "\nA version is the whole of a migration's identity in the migrations table — "
            . 'there is no column recording which source it came from. Two sources sharing one '
            . 'stops every migration command while both are enabled, and silently skips the '
            . "second one's schema when they are enabled one after the other.\n"
            . 'Give each migration a timestamp of its own, taken from when it was written.',
        );
    }

    /**
     * The map is large enough that the assertion above is examining something.
     */
    #[Test]
    public function everyShippedMigrationWasFound(): void
    {
        self::assertGreaterThanOrEqual(
            self::MINIMUM_SHIPPED_MIGRATIONS,
            count($this->versionsBySource()),
            'far fewer migrations were discovered than this tree ships — the resolver, the '
            . 'manifests or the directory layout changed, and the uniqueness assertion is now '
            . 'guarding an almost empty list',
        );
    }

    /**
     * `pulsar migrate` gets past discovery with every bundled extension enabled at once.
     *
     * The assertion above compares versions source by source; this one is the single
     * combined {@see MigrationRepository} that `bin/pulsar` builds, and it is what an
     * operator actually meets. Kept separate because the two fail differently: the one
     * above names every colliding file, this one proves the failure it prevents.
     */
    #[Test]
    public function discoveryAcrossEveryBundledSourceSucceeds(): void
    {
        $repository = new MigrationRepository($this->sources());

        try {
            $found = $repository->discover();
        } catch (DatabaseException $e) {
            self::fail(
                'Discovering every migration the framework and its bundled extensions ship '
                . 'failed, so `pulsar migrate` cannot run at all on an installation that '
                . 'enables them together: ' . $e->getMessage(),
            );
        }

        self::assertGreaterThanOrEqual(self::MINIMUM_SHIPPED_MIGRATIONS, count($found));
    }

    /**
     * Every shipped version mapped to the file paths that produce it.
     *
     * Discovered one source at a time so that a source colliding with ITSELF (two
     * filenames normalising to the same version inside one directory) is reported with
     * the same message as a cross-source collision, instead of aborting the whole scan
     * at the first sighting the way a single combined discovery would.
     *
     * @return array<string, list<string>>
     */
    private function versionsBySource(): array
    {
        $byVersion = [];

        foreach ($this->sources() as $label => $path) {
            try {
                $found = new MigrationRepository([$label => $path])->discover();
            } catch (DatabaseException $e) {
                self::fail(sprintf(
                    'The source "%s" (%s) ships two migrations that normalise to one version: %s',
                    $label,
                    $path,
                    $e->getMessage(),
                ));
            }

            foreach ($found as $version => $file) {
                $byVersion[$version][] = $file->path;
            }
        }

        ksort($byVersion);

        return $byVersion;
    }

    /**
     * Source name => migration directory, for everything the framework and its bundled
     * extensions ship.
     *
     * The project's own configured directory is deliberately absent: what an application
     * puts there is the application's business, and this repository's copy is empty.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $resolver = new MigrationPathResolver(
            new DatabaseConfig(
                defaultConnection: 'sqlite',
                connections: [],
                migrationsTable: 'pulsar_migrations',
                migrationsPath: self::ROOT . '/database/migrations',
            ),
            $this->bundledExtensions(),
        );

        $sources = [];

        foreach ($resolver->resolve() as $label => $path) {
            if ($label === 'project') {
                continue;
            }

            $sources[$label] = $path;
        }

        return $sources;
    }

    /**
     * Every extension in the tree, registered under the manifest it ships.
     *
     * Read from disk rather than from a list written out here: the collision this test
     * exists to catch arrived with a NEW extension, and a curated list is exactly what
     * would not have covered it.
     */
    private function bundledExtensions(): ExtensionRegistry
    {
        $registry = new ExtensionRegistry();

        $manifests = glob(self::ROOT . '/extensions/*/pulsar.json') ?: [];
        $nested = glob(self::ROOT . '/extensions/*/*/pulsar.json') ?: [];

        foreach ([...$manifests, ...$nested] as $manifestPath) {
            $raw = file_get_contents($manifestPath);
            self::assertIsString($raw, "unreadable manifest: {$manifestPath}");

            try {
                /** @var mixed $decoded */
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                self::fail("malformed manifest {$manifestPath}: " . $e->getMessage());
            }

            self::assertIsArray($decoded, "manifest is not an object: {$manifestPath}");

            /** @var array<string, mixed> $decoded */
            $manifest = ExtensionManifest::fromArray($decoded, dirname($manifestPath));

            $registry->add($this->extensionNamed($manifest->name), $manifest);
        }

        return $registry;
    }

    /**
     * A stand-in for the extension class the manifest names.
     *
     * Only the name is consulted: {@see MigrationPathResolver} reads the manifests and
     * the lifecycle states, never the extension objects, and loading the real classes
     * would make this test depend on every bundled extension being autoloadable.
     */
    private function extensionNamed(string $name): ExtensionInterface
    {
        return new class ($name) implements ExtensionInterface {
            public function __construct(private readonly string $extensionName) {}

            public function name(): string
            {
                return $this->extensionName;
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void {}

            public function providers(): array
            {
                return [];
            }
        };
    }

    /**
     * A duplicate report an author can act on without opening the tree.
     *
     * @param array<string, list<string>> $duplicates
     */
    private function describe(array $duplicates): string
    {
        $resolved = realpath(self::ROOT);
        $root = $resolved === false ? '' : str_replace('\\', '/', $resolved) . '/';

        $lines = [];

        foreach ($duplicates as $version => $paths) {
            $lines[] = '  ' . $version;

            foreach ($paths as $path) {
                $absolute = realpath($path);
                $normalised = str_replace('\\', '/', $absolute === false ? $path : $absolute);
                $lines[] = '    - ' . ($root !== '' && str_starts_with($normalised, $root)
                    ? substr($normalised, strlen($root))
                    : $normalised);
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
