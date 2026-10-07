<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Persistence;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Cms\Internal\Persistence\DbCssOverrideRepository;
use Pulsar\Extension\Cms\LiveCss\CssOverride;

#[CoversClass(DbCssOverrideRepository::class)]
final class DbCssOverrideRepositoryTest extends TestCase
{
    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: \Pulsar\Database\Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
            options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        // Schema without GENERATED column; the repository must use COALESCE(tenant_id, ...)
        $this->connection->execute(<<<'SQL'
            CREATE TABLE cms_css_overrides (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                theme_id VARCHAR(36) NOT NULL,
                version INTEGER NOT NULL,
                css_content TEXT NOT NULL,
                css_hash VARCHAR(128) NOT NULL,
                token_overrides TEXT DEFAULT NULL,
                is_active INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                created_by VARCHAR(36) NOT NULL,
                reason VARCHAR(500) NOT NULL
            )
            SQL);
    }

    #[Test]
    public function findActiveReturnsSingleTenantOverride(): void
    {
        // Arrange: insert active override with NULL tenant (single-tenant)
        $this->insertOverride(
            id: '11111111-1111-1111-1111-111111111111',
            tenantId: null,
            themeId: 'theme-aaa',
            version: 1,
            isActive: true,
        );

        // Act
        $repo = new DbCssOverrideRepository($this->connection);
        $result = $repo->findActive('theme-aaa', null);

        // Assert
        self::assertNotNull($result, 'findActive must return override for single-tenant (null tenant)');
        self::assertSame('11111111-1111-1111-1111-111111111111', $result->id);
        self::assertTrue($result->isActive);
    }

    #[Test]
    public function findActiveReturnsMultiTenantOverride(): void
    {
        // Arrange
        $tenantId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $this->insertOverride(
            id: '22222222-2222-2222-2222-222222222222',
            tenantId: $tenantId,
            themeId: 'theme-bbb',
            version: 1,
            isActive: true,
        );

        // Act
        $repo = new DbCssOverrideRepository($this->connection);
        $result = $repo->findActive('theme-bbb', $tenantId);

        // Assert
        self::assertNotNull($result);
        self::assertSame($tenantId, $result->tenantId);
    }

    #[Test]
    public function findActiveIsolatesTenants(): void
    {
        // Arrange
        $tenantA = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $tenantB = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

        $this->insertOverride(
            id: '33333333-3333-3333-3333-333333333333',
            tenantId: $tenantA,
            themeId: 'theme-ccc',
            version: 1,
            isActive: true,
        );

        // Act: tenant B tries to find tenant A's override
        $repo = new DbCssOverrideRepository($this->connection);
        $result = $repo->findActive('theme-ccc', $tenantB);

        // Assert
        self::assertNull($result, 'Overrides must not leak across tenants');
    }

    #[Test]
    public function findByVersionReturnsSingleTenantResult(): void
    {
        // Arrange
        $this->insertOverride(
            id: '44444444-4444-4444-4444-444444444444',
            tenantId: null,
            themeId: 'theme-ddd',
            version: 3,
            isActive: false,
        );

        // Act
        $repo = new DbCssOverrideRepository($this->connection);
        $result = $repo->findByVersion('theme-ddd', null, 3);

        // Assert
        self::assertNotNull($result, 'findByVersion must return override for single-tenant');
        self::assertSame(3, $result->version);
    }

    #[Test]
    public function getHistoryReturnsSingleTenantHistory(): void
    {
        // Arrange
        for ($v = 1; $v <= 3; $v++) {
            $this->insertOverride(
                id: "55555555-5555-5555-5555-55555555555{$v}",
                tenantId: null,
                themeId: 'theme-eee',
                version: $v,
                isActive: $v === 3,
            );
        }

        // Act
        $repo = new DbCssOverrideRepository($this->connection);
        $history = $repo->getHistory('theme-eee', null, 1, 10);

        // Assert
        self::assertCount(3, $history);
        // Should be ordered by version DESC
        self::assertSame(3, $history[0]->version);
        self::assertSame(2, $history[1]->version);
        self::assertSame(1, $history[2]->version);
    }

    #[Test]
    public function getNextVersionReturnsSingleTenantNextVersion(): void
    {
        // Arrange
        $this->insertOverride(
            id: '66666666-6666-6666-6666-666666666666',
            tenantId: null,
            themeId: 'theme-fff',
            version: 5,
            isActive: true,
        );

        // Act
        $repo = new DbCssOverrideRepository($this->connection);
        $next = $repo->getNextVersion('theme-fff', null);

        // Assert
        self::assertSame(6, $next);
    }

    #[Test]
    public function saveAndFindByIdRoundTrip(): void
    {
        // Arrange
        $override = new CssOverride(
            id: '77777777-7777-7777-7777-777777777777',
            tenantId: null,
            themeId: 'theme-ggg',
            version: 1,
            cssContent: 'body { color: red; }',
            cssHash: 'abc123',
            tokenOverrides: ['primary' => '#ff0000'],
            isActive: true,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            createdBy: 'user-001',
            reason: 'Initial override',
        );

        // Act
        $repo = new DbCssOverrideRepository($this->connection);
        $repo->save($override);
        $found = $repo->findById('77777777-7777-7777-7777-777777777777');

        // Assert
        self::assertNotNull($found);
        self::assertSame('body { color: red; }', $found->cssContent);
        self::assertSame('abc123', $found->cssHash);
        self::assertSame(['primary' => '#ff0000'], $found->tokenOverrides);
        self::assertTrue($found->isActive);
        self::assertNull($found->tenantId);
    }

    #[Test]
    public function deactivateAllAffectsSingleTenantOverrides(): void
    {
        // Arrange
        $this->insertOverride(
            id: '88888888-8888-8888-8888-888888888881',
            tenantId: null,
            themeId: 'theme-hhh',
            version: 1,
            isActive: true,
        );
        $this->insertOverride(
            id: '88888888-8888-8888-8888-888888888882',
            tenantId: null,
            themeId: 'theme-hhh',
            version: 2,
            isActive: true,
        );

        // Act
        $repo = new DbCssOverrideRepository($this->connection);
        $repo->deactivateAll('theme-hhh', null);

        // Assert
        $active = $repo->findActive('theme-hhh', null);
        self::assertNull($active, 'All overrides should be deactivated');

        $v1 = $repo->findByVersion('theme-hhh', null, 1);
        self::assertNotNull($v1);
        self::assertFalse($v1->isActive);
    }

    private function insertOverride(
        string $id,
        ?string $tenantId,
        string $themeId,
        int $version,
        bool $isActive,
    ): void {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO cms_css_overrides
                    (id, tenant_id, theme_id, version, css_content, css_hash, token_overrides, is_active, created_at, created_by, reason)
                VALUES
                    (:id, :tenant_id, :theme_id, :version, 'body{}', 'hash', '{}', :is_active, :created_at, 'user', 'test')
                SQL,
            [
                'id' => $id,
                'tenant_id' => $tenantId,
                'theme_id' => $themeId,
                'version' => $version,
                'is_active' => $isActive ? 1 : 0,
                'created_at' => new DateTimeImmutable()->format('c'),
            ],
        );
    }
}
