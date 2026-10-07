<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DiskConfig;
use Pulsar\Config\StorageConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\StorageWiring;
use Pulsar\Filesystem\Exception\UnsafeWritablePathException;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Storage\StorageManager;
use ReflectionClass;
use ReflectionProperty;

use function bin2hex;
use function file_put_contents;
use function getenv;
use function glob;
use function mkdir;
use function putenv;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function var_export;

use const DIRECTORY_SEPARATOR;

#[CoversClass(StorageWiring::class)]
final class StorageWiringTest extends TestCase
{
    private string $base;
    private string|false $originalBasePath;

    /** @var list<string> */
    private array $configPaths = [];

    protected function setUp(): void
    {
        // A project root of our own, so public_path() is a directory this test
        // controls rather than the repository's own public/.
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_storage_wiring_base_' . bin2hex(random_bytes(8));
        mkdir($this->base . DIRECTORY_SEPARATOR . 'public', 0o750, true);

        $this->originalBasePath = getenv('PULSAR_BASE_PATH');
        putenv('PULSAR_BASE_PATH=' . $this->base);
    }

    protected function tearDown(): void
    {
        if ($this->originalBasePath === false) {
            putenv('PULSAR_BASE_PATH');
        } else {
            putenv('PULSAR_BASE_PATH=' . $this->originalBasePath);
        }

        foreach ($this->configPaths as $configPath) {
            foreach (glob($configPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($configPath);
        }

        $this->configPaths = [];

        @rmdir($this->base . DIRECTORY_SEPARATOR . 'public');
        @rmdir($this->base);
    }

    #[Test]
    public function wireRegistersStorageManagerWhenConfigPresent(): void
    {
        $container = $this->wireWith([
            'local' => ['driver' => 'local', 'root' => $this->outsideRoot()],
        ]);

        self::assertTrue($container->has(StorageConfig::class));
        self::assertTrue($container->has(StorageManager::class));
    }

    #[Test]
    public function wireSkipsWhenNoStorageConfig(): void
    {
        $container = new Container();
        $configManager = $this->createConfigManager(null);
        $configManager->load();

        new StorageWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        self::assertFalse($container->has(StorageConfig::class));
        self::assertFalse($container->has(StorageManager::class));
    }

    /**
     * The row this test exists for: a disk root inside the document root means
     * every uploaded or generated file on that disk is reachable as a URL.
     */
    #[Test]
    public function aLocalDiskRootInsideTheDocumentRootIsRefusedAtWiring(): void
    {
        $this->expectException(UnsafeWritablePathException::class);
        $this->expectExceptionMessageMatches('/storage\.disks\.uploads\.root/');

        $this->wireWith([
            'uploads' => ['driver' => 'local', 'root' => 'public/uploads'],
        ]);
    }

    /**
     * The relative default is the dangerous shape: nothing about `storage/app`
     * says where it lands. Wiring resolves it, and the adapter is handed the
     * resolved value so it cannot land somewhere else.
     */
    #[Test]
    public function aRelativeRootIsResolvedAgainstTheProjectRootNotTheProcessCwd(): void
    {
        $container = $this->wireWith([
            'local' => ['driver' => 'local', 'root' => 'storage/app'],
        ]);

        self::assertSame(
            $this->base . DIRECTORY_SEPARATOR . 'storage/app',
            $this->wiredDisk($container, 'local')->root,
        );
    }

    #[Test]
    public function aDiskDeclaredPublicMayLiveInsideTheDocumentRoot(): void
    {
        $container = $this->wireWith([
            'assets' => ['driver' => 'local', 'root' => 'public/assets', 'visibility' => 'public'],
        ]);

        self::assertSame(
            $this->base . DIRECTORY_SEPARATOR . 'public/assets',
            $this->wiredDisk($container, 'assets')->root,
            'an explicitly public disk is still resolved, just not refused',
        );
    }

    /**
     * The opt-out is one exact string. A typo must fail towards the check being
     * on — the alternative is a disk that silently believes it is public.
     */
    #[Test]
    public function aMisspeltVisibilityDoesNotWaiveTheCheck(): void
    {
        $this->expectException(UnsafeWritablePathException::class);

        $this->wireWith([
            'assets' => ['driver' => 'local', 'root' => 'public/assets', 'visibility' => 'publik'],
        ]);
    }

    /**
     * Only a local disk has a filesystem root; on an S3 disk the key is inert, so
     * refusing it would reject a harmless config.
     */
    #[Test]
    public function aNonLocalDiskIsPassedThroughUntouched(): void
    {
        $container = $this->wireWith([
            'local' => ['driver' => 'local', 'root' => $this->outsideRoot()],
            'archive' => ['driver' => 's3', 'root' => 'public/archive', 'bucket' => 'b', 'region' => 'eu-west-1'],
        ]);

        $disk = $this->wiredDisk($container, 'archive');

        self::assertSame('public/archive', $disk->root);
        self::assertSame('b', $disk->bucket);
    }

    /**
     * Wiring rebuilds each local disk to swap in the resolved root. Everything
     * else on the disk — including the unknown-key report that tells an operator
     * they misspelt `visibility` — must survive that rebuild, and a property
     * added to DiskConfig later must not be dropped silently.
     */
    #[Test]
    public function rebuildingADiskPreservesEveryFieldExceptTheRoot(): void
    {
        $raw = [
            'driver' => 'local',
            'root' => 'storage/app',
            'visibility' => 'private',
            'region' => 'eu-west-1',
            'bucket' => 'b',
            'prefix' => 'p',
            'endpoint' => 'https://example.test',
            'use_path_style' => true,
            'buckett' => 'typo',
        ];

        $before = DiskConfig::fromArray('local', $raw);
        $after = $this->wiredDisk($this->wireWith(['local' => $raw]), 'local');

        self::assertNotSame($before->root, $after->root, 'the root is the one field wiring replaces');

        foreach (new ReflectionClass(DiskConfig::class)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getName() === 'root') {
                continue;
            }

            self::assertSame(
                $property->getValue($before),
                $property->getValue($after),
                "DiskConfig::\${$property->getName()} was lost when wiring rebuilt the disk",
            );
        }

        self::assertSame(['buckett'], $after->unknownKeys);
    }

    #[Test]
    public function storageLevelUnknownKeysSurviveWiring(): void
    {
        $container = $this->wireWith(
            ['local' => ['driver' => 'local', 'root' => $this->outsideRoot()]],
            ['defualt' => 'local'],
        );

        /** @var StorageConfig $config */
        $config = $container->get(StorageConfig::class);

        self::assertSame(['defualt'], $config->unknownConfigKeys());
    }

    /**
     * @param array<string, array<string, mixed>> $disks
     * @param array<string, mixed> $extra Additional top-level storage config keys
     */
    private function wireWith(array $disks, array $extra = []): Container
    {
        $container = new Container();
        $configManager = $this->createConfigManager(['default' => 'local', 'disks' => $disks, ...$extra]);
        $configManager->load();

        new StorageWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        return $container;
    }

    private function wiredDisk(Container $container, string $name): DiskConfig
    {
        /** @var StorageConfig $config */
        $config = $container->get(StorageConfig::class);

        return $config->disks[$name];
    }

    /** An absolute root that is nowhere near the document root. */
    private function outsideRoot(): string
    {
        return $this->base . DIRECTORY_SEPARATOR . 'storage';
    }

    /**
     * @param array<string, mixed>|null $storage Contents of config/storage.php, or
     *     null to omit the file entirely
     */
    private function createConfigManager(?array $storage): ConfigManager
    {
        $configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_storage_wiring_' . bin2hex(random_bytes(4));
        mkdir($configPath, 0o755, true);
        $this->configPaths[] = $configPath;

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        if ($storage !== null) {
            // var_export already emits valid PHP source, escaping included — which
            // matters on Windows, where every root carries backslashes.
            file_put_contents($configPath . '/storage.php', '<?php return ' . var_export($storage, true) . ';');
        }

        return new ConfigManager($configPath);
    }
}
