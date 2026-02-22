<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Extension\Cms\Internal\Tools\BackupService;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Tools\BackupScope;

use function bin2hex;
use function json_decode;
use function sodium_crypto_generichash;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_GENERICHASH_BYTES;

#[CoversClass(BackupService::class)]
final class BackupCommerceTablesTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    /** @var array<string, string> */
    private array $diskStorage = [];

    private BackupService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('query')->willReturn(new Result([]));

        $this->diskStorage = [];

        $disk = $this->createStub(MediaDiskInterface::class);
        $storage = &$this->diskStorage;
        $disk->method('exists')->willReturnCallback(static function (string $path) use (&$storage): bool {
            /** @var array<string, string> $storage */
            return isset($storage[$path]);
        });
        $disk->method('read')->willReturnCallback(static function (string $path) use (&$storage): string {
            /** @var array<string, string> $storage */
            return $storage[$path] ?? '';
        });
        $disk->method('write')->willReturnCallback(static function (string $path, string $contents) use (&$storage): void {
            /** @var array<string, string> $storage */
            $storage[$path] = $contents;
        });

        $this->service = new BackupService($this->connection, $disk, null);
    }

    #[Test]
    public function backup_with_commerce_includes_commerce_tables(): void
    {
        $scope = new BackupScope(
            includeContent: false,
            includeMedia: false,
            includeTaxonomies: false,
            includeMenus: false,
            includeSettings: false,
            includeCommerce: true,
        );

        $backup = $this->service->createBackup($scope, 'actor-001');

        $json = $this->diskStorage[$backup->storagePath];
        /** @var array{tables: array<string, mixed>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $tableKeys = array_keys($data['tables']);

        self::assertContains('products', $tableKeys);
        self::assertContains('product_variants', $tableKeys);
        self::assertContains('orders', $tableKeys);
        self::assertContains('order_items', $tableKeys);
        self::assertContains('order_sequences', $tableKeys);
        self::assertContains('promotions', $tableKeys);
        self::assertContains('coupons', $tableKeys);
        self::assertContains('coupon_usages', $tableKeys);
        self::assertContains('invoices', $tableKeys);
        self::assertContains('digital_assets', $tableKeys);
        self::assertContains('digital_downloads', $tableKeys);
        self::assertContains('webhook_events', $tableKeys);
    }

    #[Test]
    public function backup_without_commerce_excludes_commerce_tables(): void
    {
        $scope = new BackupScope(
            includeContent: true,
            includeMedia: false,
            includeTaxonomies: false,
            includeMenus: false,
            includeSettings: false,
            includeCommerce: false,
        );

        $backup = $this->service->createBackup($scope, 'actor-001');

        $json = $this->diskStorage[$backup->storagePath];
        /** @var array{tables: array<string, mixed>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $tableKeys = array_keys($data['tables']);

        self::assertNotContains('products', $tableKeys);
        self::assertNotContains('product_variants', $tableKeys);
        self::assertNotContains('orders', $tableKeys);
        self::assertNotContains('order_items', $tableKeys);
        self::assertNotContains('order_sequences', $tableKeys);
        self::assertNotContains('promotions', $tableKeys);
        self::assertNotContains('coupons', $tableKeys);
        self::assertNotContains('coupon_usages', $tableKeys);
        self::assertNotContains('invoices', $tableKeys);
        self::assertNotContains('digital_assets', $tableKeys);
        self::assertNotContains('digital_downloads', $tableKeys);
        self::assertNotContains('webhook_events', $tableKeys);

        // Content tables should still be present
        self::assertContains('contents', $tableKeys);
    }

    #[Test]
    public function default_scope_includes_commerce(): void
    {
        $scope = new BackupScope();

        self::assertTrue($scope->includeCommerce);
    }

    #[Test]
    public function scope_from_array_includes_commerce(): void
    {
        $scope = BackupScope::fromArray(['include_commerce' => true]);
        self::assertTrue($scope->includeCommerce);

        $scope = BackupScope::fromArray(['include_commerce' => false]);
        self::assertFalse($scope->includeCommerce);
    }

    #[Test]
    public function scope_from_array_defaults_commerce_to_true(): void
    {
        $scope = BackupScope::fromArray([]);

        self::assertTrue($scope->includeCommerce);
    }

    #[Test]
    public function scope_to_array_includes_commerce(): void
    {
        $scope = new BackupScope(includeCommerce: false);
        $array = $scope->toArray();

        self::assertArrayHasKey('include_commerce', $array);
        self::assertFalse($array['include_commerce']);
    }

    #[Test]
    public function backup_hash_covers_commerce_data(): void
    {
        $scope = new BackupScope(
            includeContent: false,
            includeMedia: false,
            includeTaxonomies: false,
            includeMenus: false,
            includeSettings: false,
            includeCommerce: true,
        );

        $backup = $this->service->createBackup($scope, 'actor-001');

        $json = $this->diskStorage[$backup->storagePath];
        $expectedHash = bin2hex(sodium_crypto_generichash($json, '', SODIUM_CRYPTO_GENERICHASH_BYTES));

        self::assertSame($expectedHash, $backup->hash);
    }
}
