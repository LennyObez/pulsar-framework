<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Forms\FormSubmission;
use Pulsar\Extension\Cms\Forms\FormSubmissionRepositoryInterface;

use function implode;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed form submission repository.
 */
#[Internal(reason: 'Raw-DB repository — use FormSubmissionRepositoryInterface for public API')]
final readonly class DbFormSubmissionRepository implements FormSubmissionRepositoryInterface
{
    private const array UPSERT_COLUMNS = [
        'id', 'form_block_id', 'content_id', 'tenant_id', 'data',
        'ip_hash', 'user_agent_hash', 'submitted_at', 'evidence_hash',
        'is_read', 'is_spam', 'spam_score', 'spam_reason',
    ];

    private const array UPSERT_UPDATE = [
        'is_read', 'is_spam', 'spam_score', 'spam_reason',
    ];

    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_form_submissions WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_ID_TENANT = <<<'SQL'
        SELECT * FROM cms_form_submissions WHERE id = :id AND tenant_id = :tenant_id
        SQL;

    private const string SQL_FIND_BY_CONTENT = <<<'SQL'
        SELECT * FROM cms_form_submissions WHERE content_id = :content_id ORDER BY submitted_at DESC
        SQL;

    private const string SQL_FIND_BY_CONTENT_TENANT = <<<'SQL'
        SELECT * FROM cms_form_submissions WHERE content_id = :content_id AND tenant_id = :tenant_id ORDER BY submitted_at DESC
        SQL;

    private const string SQL_FIND_BY_TENANT = <<<'SQL'
        SELECT * FROM cms_form_submissions WHERE tenant_id = :tenant_id ORDER BY submitted_at DESC
        SQL;

    private const string SQL_MARK_READ = <<<'SQL'
        UPDATE cms_form_submissions SET is_read = 1 WHERE id = :id
        SQL;

    private const string SQL_MARK_SPAM = <<<'SQL'
        UPDATE cms_form_submissions SET is_spam = 1, spam_reason = :reason WHERE id = :id
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM cms_form_submissions WHERE id = :id
        SQL;

    private const string SQL_COUNT_UNREAD = <<<'SQL'
        SELECT COUNT(*) AS total FROM cms_form_submissions WHERE is_read = 0
        SQL;

    private const string SQL_COUNT_UNREAD_TENANT = <<<'SQL'
        SELECT COUNT(*) AS total FROM cms_form_submissions WHERE is_read = 0 AND tenant_id = :tenant_id
        SQL;

    // findAll() builds its query dynamically to support filtering combinations.

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function save(FormSubmission $submission): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_form_submissions',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $submission->id,
            'form_block_id' => $submission->formBlockId,
            'content_id' => $submission->contentId,
            'tenant_id' => $submission->tenantId,
            'data' => json_encode($submission->data, JSON_THROW_ON_ERROR),
            'ip_hash' => $submission->ipHash,
            'user_agent_hash' => $submission->userAgentHash,
            'submitted_at' => $submission->submittedAt->format('c'),
            'evidence_hash' => $submission->evidenceHash,
            'is_read' => $submission->isRead ? 1 : 0,
            'is_spam' => $submission->isSpam ? 1 : 0,
            'spam_score' => $submission->spamScore,
            'spam_reason' => $submission->spamReason,
        ]);
    }

    #[Override]
    public function findById(string $id, ?string $tenantId = null): ?FormSubmission
    {
        if ($tenantId !== null) {
            $result = $this->connection->query(self::SQL_FIND_BY_ID_TENANT, [
                'id' => $id,
                'tenant_id' => $tenantId,
            ]);
        } else {
            $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        }

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    #[Override]
    public function findByContentId(string $contentId, ?string $tenantId = null): array
    {
        if ($tenantId !== null) {
            $result = $this->connection->query(self::SQL_FIND_BY_CONTENT_TENANT, [
                'content_id' => $contentId,
                'tenant_id' => $tenantId,
            ]);
        } else {
            $result = $this->connection->query(self::SQL_FIND_BY_CONTENT, [
                'content_id' => $contentId,
            ]);
        }

        return $result->map(self::hydrate(...));
    }

    #[Override]
    public function findByTenantId(string $tenantId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_TENANT, [
            'tenant_id' => $tenantId,
        ]);

        return $result->map(self::hydrate(...));
    }

    #[Override]
    public function markAsRead(string $id): void
    {
        $this->connection->execute(self::SQL_MARK_READ, ['id' => $id]);
    }

    #[Override]
    public function markAsSpam(string $id, string $reason): void
    {
        $this->connection->execute(self::SQL_MARK_SPAM, [
            'id' => $id,
            'reason' => $reason,
        ]);
    }

    #[Override]
    public function delete(string $id): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $id]);
    }

    #[Override]
    public function countUnread(?string $tenantId = null): int
    {
        if ($tenantId !== null) {
            $result = $this->connection->query(self::SQL_COUNT_UNREAD_TENANT, [
                'tenant_id' => $tenantId,
            ]);
        } else {
            $result = $this->connection->query(self::SQL_COUNT_UNREAD);
        }

        return $result->first()?->getInt('total') ?? 0;
    }

    #[Override]
    public function findAll(
        ?string $tenantId = null,
        bool $includeSpam = false,
        int $limit = 50,
        int $offset = 0,
        ?bool $unreadOnly = null,
    ): array {
        $conditions = [];
        $params = ['limit' => $limit, 'offset' => $offset];

        if ($tenantId !== null) {
            $conditions[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        if (!$includeSpam) {
            $conditions[] = 'is_spam = 0';
        }

        if ($unreadOnly === true) {
            $conditions[] = 'is_read = 0';
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT * FROM cms_form_submissions $where ORDER BY submitted_at DESC LIMIT :limit OFFSET :offset";

        $result = $this->connection->query($sql, $params);

        return $result->map(self::hydrate(...));
    }

    private static function hydrate(Row $row): FormSubmission
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($row->getString('data'), true, 512, JSON_THROW_ON_ERROR);

        return new FormSubmission(
            id: $row->getString('id'),
            formBlockId: $row->getString('form_block_id'),
            contentId: $row->getString('content_id'),
            tenantId: $row->getNullableString('tenant_id'),
            data: $data,
            ipHash: $row->getString('ip_hash'),
            userAgentHash: $row->getString('user_agent_hash'),
            submittedAt: new DateTimeImmutable($row->getString('submitted_at')),
            evidenceHash: $row->getString('evidence_hash'),
            isRead: $row->getInt('is_read') === 1,
            isSpam: $row->getInt('is_spam') === 1,
            spamScore: (float) $row->getString('spam_score'),
            spamReason: $row->getNullableString('spam_reason'),
        );
    }
}
