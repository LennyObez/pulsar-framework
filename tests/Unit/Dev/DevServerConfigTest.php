<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\DevServerConfig;
use ReflectionClass;
use stdClass;

#[CoversClass(DevServerConfig::class)]
final class DevServerConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new DevServerConfig(extensionName: 'test');

        self::assertSame('test', $config->extensionName);
        self::assertSame([], $config->assetPrefixes);
        self::assertSame([], $config->templatePaths);
        self::assertSame([], $config->containerBindings);
        self::assertTrue($config->injectDevIdentity);
        self::assertSame(['admin', 'editor', 'moderator'], $config->devIdentityRoles);
        self::assertTrue($config->autoMigrate);
        self::assertSame([], $config->migrationDirs);
        self::assertSame([], $config->setupSql);
        self::assertSame([], $config->seeders);
        self::assertSame([], $config->criticalTables);
        self::assertSame([], $config->redirects);
    }

    public function testCustomValues(): void
    {
        $seeder = static function (): void {};
        $binding = new stdClass();

        $config = new DevServerConfig(
            extensionName: 'admin',
            assetPrefixes: ['/admin/assets/' => ['frontend/styles']],
            templatePaths: ['/templates'],
            containerBindings: ['SomeClass' => $binding],
            injectDevIdentity: false,
            devIdentityRoles: ['viewer'],
            autoMigrate: false,
            migrationDirs: ['migrations'],
            setupSql: ['CREATE TABLE test (id INT)'],
            seeders: [$seeder],
            criticalTables: ['users'],
            redirects: ['/old' => '/new'],
        );

        self::assertSame('admin', $config->extensionName);
        self::assertSame(['/admin/assets/' => ['frontend/styles']], $config->assetPrefixes);
        self::assertSame(['/templates'], $config->templatePaths);
        self::assertSame(['SomeClass' => $binding], $config->containerBindings);
        self::assertFalse($config->injectDevIdentity);
        self::assertSame(['viewer'], $config->devIdentityRoles);
        self::assertFalse($config->autoMigrate);
        self::assertSame(['migrations'], $config->migrationDirs);
        self::assertSame(['CREATE TABLE test (id INT)'], $config->setupSql);
        self::assertSame([$seeder], $config->seeders);
        self::assertSame(['users'], $config->criticalTables);
        self::assertSame(['/old' => '/new'], $config->redirects);
    }

    public function testIsReadonly(): void
    {
        $config = new DevServerConfig(extensionName: 'studio');

        $reflection = new ReflectionClass($config);

        self::assertTrue($reflection->isReadOnly());
    }

    public function testMultipleAssetPrefixes(): void
    {
        $config = new DevServerConfig(
            extensionName: 'cms',
            assetPrefixes: [
                '/admin/assets/' => ['extensions/admin/frontend/styles', 'extensions/admin/frontend/dist'],
                '/admin/cms/assets/' => ['extensions/cms/frontend/styles'],
            ],
        );

        self::assertCount(2, $config->assetPrefixes);
        self::assertCount(2, $config->assetPrefixes['/admin/assets/']);
        self::assertCount(1, $config->assetPrefixes['/admin/cms/assets/']);
    }
}
