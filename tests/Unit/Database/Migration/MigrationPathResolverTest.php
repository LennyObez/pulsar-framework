<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Database\Migration\MigrationPathResolver;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;

use const DIRECTORY_SEPARATOR;

#[CoversClass(MigrationPathResolver::class)]
final class MigrationPathResolverTest extends TestCase
{
    #[Test]
    public function resolveReturnsProjectPathWhenNoExtensions(): void
    {
        // Arrange
        $config = $this->createDatabaseConfig('database/migrations');
        $resolver = new MigrationPathResolver($config);

        // Act
        $paths = $resolver->resolve();

        // Assert
        self::assertSame(['database/migrations'], $paths);
    }

    #[Test]
    public function resolveReturnsProjectPathWhenRegistryIsNull(): void
    {
        // Arrange
        $config = $this->createDatabaseConfig('database/migrations');
        $resolver = new MigrationPathResolver($config, null);

        // Act
        $paths = $resolver->resolve();

        // Assert
        self::assertSame(['database/migrations'], $paths);
    }

    #[Test]
    public function resolveReturnsProjectPathWhenNoExtensionsHaveMigrations(): void
    {
        // Arrange
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = new ExtensionRegistry();
        $resolver = new MigrationPathResolver($config, $registry);

        // Act
        $paths = $resolver->resolve();

        // Assert
        self::assertSame(['database/migrations'], $paths);
    }

    #[Test]
    public function resolveIncludesExtensionMigrationPaths(): void
    {
        // Arrange
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = $this->createRegistryWithExtension(
            'pulsar/cms',
            '/framework/extensions/cms',
            ['src/Migration'],
        );
        $resolver = new MigrationPathResolver($config, $registry);

        // Act
        $paths = $resolver->resolve();

        // Assert
        self::assertCount(2, $paths);
        self::assertSame('database/migrations', $paths[0]);
        self::assertSame('/framework/extensions/cms' . DIRECTORY_SEPARATOR . 'src/Migration', $paths[1]);
    }

    #[Test]
    public function resolveProjectPathIsAlwaysFirst(): void
    {
        // Arrange
        $config = $this->createDatabaseConfig('project/migrations');
        $registry = $this->createRegistryWithExtension(
            'pulsar/cms',
            '/ext/cms',
            ['src/Migration'],
        );
        $resolver = new MigrationPathResolver($config, $registry);

        // Act
        $paths = $resolver->resolve();

        // Assert
        self::assertSame('project/migrations', $paths[0]);
    }

    #[Test]
    public function resolveHandlesMultipleExtensions(): void
    {
        // Arrange
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = new ExtensionRegistry();

        $this->addExtensionToRegistry($registry, 'pulsar/cms', '/ext/cms', ['src/Migration']);
        $this->addExtensionToRegistry($registry, 'pulsar/forum', '/ext/forum', ['src/Migration']);
        $this->addExtensionToRegistry($registry, 'pulsar/analytics', '/ext/analytics', ['src/Migration']);

        $resolver = new MigrationPathResolver($config, $registry);

        // Act
        $paths = $resolver->resolve();

        // Assert — project + 3 extensions
        self::assertCount(4, $paths);
        self::assertSame('database/migrations', $paths[0]);
        self::assertStringContainsString('cms', $paths[1]);
        self::assertStringContainsString('forum', $paths[2]);
        self::assertStringContainsString('analytics', $paths[3]);
    }

    #[Test]
    public function resolveHandlesExtensionWithMultipleMigrationPaths(): void
    {
        // Arrange
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = $this->createRegistryWithExtension(
            'pulsar/cms',
            '/ext/cms',
            ['src/Migration', 'database/migrations'],
        );
        $resolver = new MigrationPathResolver($config, $registry);

        // Act
        $paths = $resolver->resolve();

        // Assert — project + 2 extension paths
        self::assertCount(3, $paths);
        self::assertSame('database/migrations', $paths[0]);
        self::assertSame('/ext/cms' . DIRECTORY_SEPARATOR . 'src/Migration', $paths[1]);
        self::assertSame('/ext/cms' . DIRECTORY_SEPARATOR . 'database/migrations', $paths[2]);
    }

    #[Test]
    public function resolveSkipsExtensionsWithoutMigrations(): void
    {
        // Arrange
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = new ExtensionRegistry();

        $this->addExtensionToRegistry($registry, 'pulsar/cms', '/ext/cms', ['src/Migration']);
        $this->addExtensionToRegistry($registry, 'pulsar/example', '/ext/example', []); // no migrations

        $resolver = new MigrationPathResolver($config, $registry);

        // Act
        $paths = $resolver->resolve();

        // Assert: project + 1 extension (example skipped, no migrations declared)
        self::assertCount(2, $paths);
    }

    #[Test]
    public function resolveExcludesFailedExtensions(): void
    {
        // Arrange
        $config = $this->createDatabaseConfig('database/migrations');
        $registry = new ExtensionRegistry();

        $this->addExtensionToRegistry($registry, 'pulsar/cms', '/ext/cms', ['src/Migration']);
        $this->addExtensionToRegistry($registry, 'pulsar/feedback', '/ext/feedback', ['src/Migration']);

        // Mark feedback as failed (simulates boot failure)
        $registry->setState('pulsar/feedback', \Pulsar\Extensibility\ExtensionLifecycle::Failed);

        $resolver = new MigrationPathResolver($config, $registry);

        // Act
        $paths = $resolver->resolve();

        // Assert: project + CMS only (feedback excluded because it failed)
        self::assertCount(2, $paths);
        self::assertSame('database/migrations', $paths[0]);
        self::assertStringContainsString('cms', $paths[1]);
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
            'version' => '1.0.0',
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
