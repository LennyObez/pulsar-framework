<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Database\Migration\MigrationPathResolver;
use Pulsar\Extensibility\ExtensionLifecycle;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;

use function array_search;
use function array_slice;
use function array_values;
use function is_int;
use function str_replace;

use const DIRECTORY_SEPARATOR;

#[CoversClass(MigrationPathResolver::class)]
final class MigrationPathResolverTest extends TestCase
{
    /**
     * The framework's own `src/<Module>/Database/Migration` directories lead the list.
     *
     * They are found by glob so that a core module gaining migrations is picked up
     * without anyone editing the resolver — which is also why no test below may assume
     * how many there are.
     */
    #[Test]
    public function frameworkMigrationsPrecedeTheProjectPath(): void
    {
        $resolver = new MigrationPathResolver($this->createDatabaseConfig('database/migrations'));

        $paths = $resolver->resolve();
        $index = array_search('database/migrations', $paths, true);

        self::assertGreaterThan(0, $index, 'the framework ships migrations and none were discovered');

        foreach (array_slice($paths, 0, is_int($index) ? $index : 0) as $framework) {
            self::assertStringEndsWith('/Database/Migration', str_replace('\\', '/', $framework));
        }
    }

    #[Test]
    public function resolveReturnsProjectPathWhenNoExtensions(): void
    {
        $config = $this->createDatabaseConfig('database/migrations');
        $resolver = new MigrationPathResolver($config);

        $paths = $resolver->resolve();

        self::assertSame(['database/migrations'], $this->fromProjectPath($paths, 'database/migrations'));
    }

    #[Test]
    public function resolveReturnsProjectPathWhenRegistryIsNull(): void
    {
        $config = $this->createDatabaseConfig('database/migrations');
        $resolver = new MigrationPathResolver($config, null);

        $paths = $resolver->resolve();

        self::assertSame(['database/migrations'], $this->fromProjectPath($paths, 'database/migrations'));
    }

    #[Test]
    public function resolveReturnsProjectPathWhenNoExtensionsHaveMigrations(): void
    {
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = new ExtensionRegistry();
        $resolver = new MigrationPathResolver($config, $registry);

        $paths = $resolver->resolve();

        self::assertSame(['database/migrations'], $this->fromProjectPath($paths, 'database/migrations'));
    }

    #[Test]
    public function resolveIncludesExtensionMigrationPaths(): void
    {
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = $this->createRegistryWithExtension(
            'pulsar/cms',
            '/framework/extensions/cms',
            ['src/Migration'],
        );
        $resolver = new MigrationPathResolver($config, $registry);

        $tail = $this->fromProjectPath($resolver->resolve(), 'database/migrations');

        self::assertCount(2, $tail);
        self::assertSame('database/migrations', $tail[0]);
        self::assertSame('/framework/extensions/cms' . DIRECTORY_SEPARATOR . 'src/Migration', $tail[1]);
    }

    /**
     * Extension migrations run after the project's, so a project schema is never built
     * on top of a table an extension has yet to create.
     */
    #[Test]
    public function projectPathPrecedesEveryExtensionPath(): void
    {
        $config = $this->createDatabaseConfig('project/migrations');
        $registry = $this->createRegistryWithExtension(
            'pulsar/cms',
            '/ext/cms',
            ['src/Migration'],
        );
        $resolver = new MigrationPathResolver($config, $registry);

        $tail = $this->fromProjectPath($resolver->resolve(), 'project/migrations');

        self::assertSame('project/migrations', $tail[0]);
        self::assertStringContainsString('cms', $tail[1]);
    }

    #[Test]
    public function resolveHandlesMultipleExtensions(): void
    {
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = new ExtensionRegistry();

        $this->addExtensionToRegistry($registry, 'pulsar/cms', '/ext/cms', ['src/Migration']);
        $this->addExtensionToRegistry($registry, 'pulsar/forum', '/ext/forum', ['src/Migration']);
        $this->addExtensionToRegistry($registry, 'pulsar/analytics', '/ext/analytics', ['src/Migration']);

        $resolver = new MigrationPathResolver($config, $registry);

        $tail = $this->fromProjectPath($resolver->resolve(), 'database/migrations');

        self::assertCount(4, $tail);
        self::assertSame('database/migrations', $tail[0]);
        self::assertStringContainsString('cms', $tail[1]);
        self::assertStringContainsString('forum', $tail[2]);
        self::assertStringContainsString('analytics', $tail[3]);
    }

    #[Test]
    public function resolveHandlesExtensionWithMultipleMigrationPaths(): void
    {
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = $this->createRegistryWithExtension(
            'pulsar/cms',
            '/ext/cms',
            ['src/Migration', 'database/migrations'],
        );
        $resolver = new MigrationPathResolver($config, $registry);

        $tail = $this->fromProjectPath($resolver->resolve(), 'database/migrations');

        self::assertCount(3, $tail);
        self::assertSame('database/migrations', $tail[0]);
        self::assertSame('/ext/cms' . DIRECTORY_SEPARATOR . 'src/Migration', $tail[1]);
        self::assertSame('/ext/cms' . DIRECTORY_SEPARATOR . 'database/migrations', $tail[2]);
    }

    #[Test]
    public function resolveSkipsExtensionsWithoutMigrations(): void
    {
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = new ExtensionRegistry();

        $this->addExtensionToRegistry($registry, 'pulsar/cms', '/ext/cms', ['src/Migration']);
        $this->addExtensionToRegistry($registry, 'pulsar/example', '/ext/example', []);

        $resolver = new MigrationPathResolver($config, $registry);

        self::assertCount(2, $this->fromProjectPath($resolver->resolve(), 'database/migrations'));
    }

    #[Test]
    public function resolveExcludesFailedExtensions(): void
    {
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = new ExtensionRegistry();

        $this->addExtensionToRegistry($registry, 'pulsar/cms', '/ext/cms', ['src/Migration']);
        $this->addExtensionToRegistry($registry, 'pulsar/feedback', '/ext/feedback', ['src/Migration']);

        $registry->setState('pulsar/feedback', ExtensionLifecycle::Failed);

        $resolver = new MigrationPathResolver($config, $registry);

        $tail = $this->fromProjectPath($resolver->resolve(), 'database/migrations');

        self::assertCount(2, $tail);
        self::assertSame('database/migrations', $tail[0]);
        self::assertStringContainsString('cms', $tail[1]);
    }

    /**
     * The resolved list from the project path onwards, so an assertion about what the
     * project and its extensions contribute does not depend on how many migration
     * directories the framework itself happens to ship.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function fromProjectPath(array $paths, string $projectPath): array
    {
        $index = array_search($projectPath, $paths, true);

        if (!is_int($index)) {
            self::fail("the resolved list does not contain the project path '{$projectPath}'");
        }

        return array_values(array_slice($paths, $index));
    }

    private function createDatabaseConfig(string $migrationsPath): DatabaseConfig
    {
        return new DatabaseConfig(
            defaultConnection: 'sqlite',
            connections: [],
            migrationsTable: 'pulsar_migrations',
            migrationsPath: $migrationsPath,
        );
    }

    /**
     * @param list<string> $migrations
     */
    private function createRegistryWithExtension(
        string $name,
        string $basePath,
        array $migrations,
    ): ExtensionRegistry {
        $registry = new ExtensionRegistry();
        $this->addExtensionToRegistry($registry, $name, $basePath, $migrations);

        return $registry;
    }

    /**
     * @param list<string> $migrations
     */
    private function addExtensionToRegistry(
        ExtensionRegistry $registry,
        string $name,
        string $basePath,
        array $migrations,
    ): void {
        $manifest = ExtensionManifest::fromArray([
            'name' => $name,
            'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'extension_class' => 'Pulsar\\Test\\FakeExtension',
            'provides' => [
                'migrations' => $migrations,
            ],
        ], $basePath);

        $extension = new class ($name) implements \Pulsar\Extensibility\ExtensionInterface {
            public function __construct(private string $extName) {}
            public function name(): string
            {
                return $this->extName;
            }
            public function register(\Pulsar\Container\ContainerInterface $container): void {}
            public function boot(\Pulsar\Container\ContainerInterface $container, \Pulsar\Routing\RouterInterface $router): void {}
            public function providers(): array
            {
                return [];
            }
        };

        $registry->add($extension, $manifest);
    }
}
