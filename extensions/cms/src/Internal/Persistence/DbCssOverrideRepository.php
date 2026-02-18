<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\LiveCss\CssOverride;
use Pulsar\Extension\Cms\LiveCss\CssOverrideRepositoryInterface;

use function json_decode;
use function json_encode;
use function max;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed repository for CSS override persistence.
 */
#[Internal(reason: 'Raw-DB repository — use CssOverrideRepositoryInterface for public API')]
final readonly class DbCssOverrideRepository implements CssOverrideRepositoryInterface
{
    private const string TENANT_SENTINEL = '__GLOBAL__';

    private const string SQL_FIND_ACTIVE = <<<'SQL'
        SELECT * FROM cms_css_overrides
        WHERE theme_id = :theme_id AND tenant_key = :tenant_key AND is_active = true
        LIMIT 1
        SQL;

    private const string SQL_FIND_BY_VERSION = <<<'SQL'
        SELECT * FROM cms_css_overrides
        WHERE theme_id = :theme_id AND tenant_key = :tenant_key AND version = :version
        LIMIT 1
        SQL;

    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_css_overrides WHERE id = :id
        SQL;

    private const string SQL_HISTORY = <<<'SQL'
        SELECT * FROM cms_css_overrides
        WHERE theme_id = :theme_id AND tenant_key = :tenant_key
        ORDER BY version DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_MAX_VERSION = <<<'SQL'
        SELECT COALESCE(MAX(version), 0) AS max_version
        FROM cms_css_overrides
        WHERE theme_id = :theme_id AND tenant_key = :tenant_key
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO cms_css_overrides (
            id, tenant_id, tenant_key, theme_id, version, css_content, css_hash,
            token_overrides, is_active, created_at, created_by, reason
        ) VALUES (
            :id, :tenant_id, :tenant_key, :theme_id, :version, :css_content, :css_hash,
            :token_overrides, :is_active, :created_at, :created_by, :reason
        )
        ON CONFLICT (id) DO UPDATE SET
            is_active = EXCLUDED.is_active
        SQL;

    private const string SQL_DEACTIVATE_ALL = <<<'SQL'
        UPDATE cms_css_overrides
        SET is_active = false
        WHERE theme_id = :theme_id AND tenant_key = :tenant_key AND is_active = true
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findActive(string $themeId, ?string $tenantId = null): ?CssOverride
    {
        $result = $this->connection->query(self::SQL_FIND_ACTIVE, [
            'theme_id' => $themeId,
            'tenant_key' => $tenantId ?? self::TENANT_SENTINEL,
        ]);

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByVersion(string $themeId, ?string $tenantId, int $version): ?CssOverride
    {
        $result = $this->connection->query(self::SQL_FIND_BY_VERSION, [
            'theme_id' => $themeId,
            'tenant_key' => $tenantId ?? self::TENANT_SENTINEL,
            'version' => $version,
        ]);

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findById(string $id): ?CssOverride
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function getHistory(string $themeId, ?string $tenantId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $result = $this->connection->query(self::SQL_HISTORY, [
            'theme_id' => $themeId,
            'tenant_key' => $tenantId ?? self::TENANT_SENTINEL,
            'limit' => $perPage,
            'offset' => $offset,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function getNextVersion(string $themeId, ?string $tenantId): int
    {
        $result = $this->connection->query(self::SQL_MAX_VERSION, [
            'theme_id' => $themeId,
            'tenant_key' => $tenantId ?? self::TENANT_SENTINEL,
        ]);

        $row = $result->first();

        return $row !== null ? max(1, $row->getInt('max_version') + 1) : 1;
    }

    public function save(CssOverride $override): void
    {
        $this->connection->execute(self::SQL_UPSERT, [
            'id' => $override->id,
            'tenant_id' => $override->tenantId,
            'tenant_key' => $override->tenantId ?? self::TENANT_SENTINEL,
            'theme_id' => $override->themeId,
            'version' => $override->version,
            'css_content' => $override->cssContent,
            'css_hash' => $override->cssHash,
            'token_overrides' => json_encode($override->tokenOverrides, JSON_THROW_ON_ERROR),
            'is_active' => $override->isActive,
            'created_at' => $override->createdAt->format('c'),
            'created_by' => $override->createdBy,
            'reason' => $override->reason,
        ]);
    }

    public function deactivateAll(string $themeId, ?string $tenantId): void
    {
        $this->connection->execute(self::SQL_DEACTIVATE_ALL, [
            'theme_id' => $themeId,
            'tenant_key' => $tenantId ?? self::TENANT_SENTINEL,
        ]);
    }

    private static function hydrate(Row $row): CssOverride
    {
        /** @var array<string, string> $tokenOverrides */
        $tokenOverrides = json_decode($row->getString('token_overrides'), true, 512, JSON_THROW_ON_ERROR);

        return new CssOverride(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            themeId: $row->getString('theme_id'),
            version: $row->getInt('version'),
            cssContent: $row->getString('css_content'),
            cssHash: $row->getString('css_hash'),
            tokenOverrides: $tokenOverrides,
            isActive: $row->getBool('is_active'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            createdBy: $row->getString('created_by'),
            reason: $row->getString('reason'),
        );
    }
}
