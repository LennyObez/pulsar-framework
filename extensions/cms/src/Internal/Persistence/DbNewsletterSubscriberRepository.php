<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriber;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\SubscriberStatus;

use function ceil;
use function max;

/**
 * Database-backed newsletter subscriber repository.
 *
 * @psalm-api Bound to NewsletterSubscriberRepositoryInterface in the CMS service
 *            provider; resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Raw-DB repository; use NewsletterSubscriberRepositoryInterface for public API')]
final readonly class DbNewsletterSubscriberRepository implements NewsletterSubscriberRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT s.*
        FROM cms_newsletter_subscribers s
        WHERE s.id = :id
        SQL;

    private const string SQL_FIND_BY_EMAIL = <<<'SQL'
        SELECT s.*
        FROM cms_newsletter_subscribers s
        WHERE s.email = :email
        SQL;

    private const string SQL_FIND_BY_EMAIL_TENANT = <<<'SQL'
        SELECT s.*
        FROM cms_newsletter_subscribers s
        WHERE s.email = :email AND s.tenant_id = :tenant_id
        SQL;

    private const string SQL_COUNT_BY_STATUS = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM cms_newsletter_subscribers s
        WHERE s.status = :status
        SQL;

    private const string SQL_FIND_BY_STATUS = <<<'SQL'
        SELECT s.*
        FROM cms_newsletter_subscribers s
        WHERE s.status = :status
        SQL;

    private const string SQL_FIND_CONFIRMED = <<<'SQL'
        SELECT s.*
        FROM cms_newsletter_subscribers s
        WHERE s.status = 'confirmed'
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM cms_newsletter_subscribers WHERE id = :id
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'email', 'user_id', 'locale', 'status',
        'confirm_token_hash', 'confirmed_at', 'unsubscribed_at',
        'ip_address_hash', 'source', 'tenant_id', 'created_at',
    ];

    private const array UPSERT_UPDATE = [
        'status', 'confirm_token_hash', 'confirmed_at',
        'unsubscribed_at',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(NewsletterSubscriber $subscriber): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_newsletter_subscribers',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $subscriber->id,
            'email' => $subscriber->email,
            'user_id' => $subscriber->userId,
            'locale' => $subscriber->locale,
            'status' => $subscriber->status->value,
            'confirm_token_hash' => $subscriber->confirmTokenHash,
            'confirmed_at' => $subscriber->confirmedAt?->format('c'),
            'unsubscribed_at' => $subscriber->unsubscribedAt?->format('c'),
            'ip_address_hash' => $subscriber->ipAddressHash,
            'source' => $subscriber->source,
            'tenant_id' => $subscriber->tenantId,
            'created_at' => $subscriber->createdAt->format('c'),
        ]);
    }

    public function findById(string $id): ?NewsletterSubscriber
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByEmail(string $email, ?string $tenantId = null): ?NewsletterSubscriber
    {
        if ($tenantId !== null) {
            $result = $this->connection->query(
                self::SQL_FIND_BY_EMAIL_TENANT,
                ['email' => $email, 'tenant_id' => $tenantId],
            );
        } else {
            $result = $this->connection->query(
                self::SQL_FIND_BY_EMAIL . ' AND s.tenant_id IS NULL',
                ['email' => $email],
            );
        }

        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByStatus(
        SubscriberStatus $status,
        ?string $tenantId = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $bindings = ['status' => $status->value];

        $countSql = self::SQL_COUNT_BY_STATUS;
        $selectSql = self::SQL_FIND_BY_STATUS;

        if ($tenantId !== null) {
            $tenantFilter = ' AND s.tenant_id = :tenant_id';
            $countSql .= $tenantFilter;
            $selectSql .= $tenantFilter;
            $bindings['tenant_id'] = $tenantId;
        }

        $countResult = $this->connection->query($countSql, $bindings);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql .= ' ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset';
        $bindings['limit'] = $perPage;
        $bindings['offset'] = $offset;

        $dataResult = $this->connection->query($selectSql, $bindings);
        $items = $dataResult->map(self::hydrate(...));
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function findAllConfirmed(?string $locale = null, ?string $tenantId = null): array
    {
        $sql = self::SQL_FIND_CONFIRMED;
        $bindings = [];

        if ($locale !== null) {
            $sql .= ' AND s.locale = :locale';
            $bindings['locale'] = $locale;
        }

        if ($tenantId !== null) {
            $sql .= ' AND s.tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        $sql .= ' ORDER BY s.created_at ASC';

        $result = $this->connection->query($sql, $bindings);

        return $result->map(self::hydrate(...));
    }

    public function countByStatus(SubscriberStatus $status, ?string $tenantId = null): int
    {
        $sql = self::SQL_COUNT_BY_STATUS;
        $bindings = ['status' => $status->value];

        if ($tenantId !== null) {
            $sql .= ' AND s.tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        $result = $this->connection->query($sql, $bindings);

        return $result->first()?->getInt('total') ?? 0;
    }

    public function delete(NewsletterSubscriber $subscriber): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $subscriber->id]);
    }

    private static function hydrate(Row $row): NewsletterSubscriber
    {
        return new NewsletterSubscriber(
            id: $row->getString('id'),
            email: $row->getString('email'),
            userId: $row->getNullableString('user_id'),
            locale: $row->getString('locale'),
            status: SubscriberStatus::from($row->getString('status')),
            confirmTokenHash: $row->getNullableString('confirm_token_hash'),
            confirmedAt: self::toDateTime($row->getNullableString('confirmed_at')),
            unsubscribedAt: self::toDateTime($row->getNullableString('unsubscribed_at')),
            ipAddressHash: $row->getString('ip_address_hash'),
            source: $row->getString('source'),
            tenantId: $row->getNullableString('tenant_id'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
