<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Tools\ToolsServiceInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function count;

/**
 * GDPR tools service implementation.
 *
 * Provides data export and PII erasure for CMS-managed user data.
 * All operations are logged to the audit trail.
 */
#[Internal(reason: 'GDPR tools implementation — use ToolsServiceInterface')]
final readonly class ToolsService implements ToolsServiceInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private ?AuditLoggerInterface $auditLogger,
    ) {}

    public function exportUserData(string $userId): array
    {
        $data = [];

        // Content authored by user
        $contentResult = $this->connection->query(
            'SELECT id, content_type, status, created_at, updated_at, published_at FROM cms_contents WHERE author_id = :user_id AND deleted_at IS NULL',
            ['user_id' => $userId],
        );
        $data['content'] = $contentResult->map(static fn(Row $row): array => $row->toArray());

        // Content translations for user's content
        $translationResult = $this->connection->query(
            'SELECT ct.id, ct.content_id, ct.locale, ct.title, ct.slug_segment, ct.path, ct.excerpt, ct.body, ct.meta_title, ct.meta_description FROM cms_content_translations ct INNER JOIN cms_contents c ON c.id = ct.content_id WHERE c.author_id = :user_id AND c.deleted_at IS NULL',
            ['user_id' => $userId],
        );
        $data['translations'] = $translationResult->map(static fn(Row $row): array => $row->toArray());

        // Comments by user
        $commentResult = $this->connection->query(
            'SELECT id, content_id, body, status, ip_hash, user_agent_hash, created_at FROM cms_comments WHERE author_id = :user_id AND deleted_at IS NULL',
            ['user_id' => $userId],
        );
        $data['comments'] = $commentResult->map(static fn(Row $row): array => $row->toArray());

        // Media uploaded by user
        $mediaResult = $this->connection->query(
            'SELECT id, filename, mime_type, file_size, visibility, created_at FROM cms_media WHERE uploader_id = :user_id AND deleted_at IS NULL',
            ['user_id' => $userId],
        );
        $data['media'] = $mediaResult->map(static fn(Row $row): array => $row->toArray());

        // Settings changes by user (from audit log)
        $settingsResult = $this->connection->query(
            'SELECT id, setting_group, setting_key, old_value, new_value, changed_at FROM cms_settings_history WHERE changed_by = :user_id ORDER BY changed_at DESC',
            ['user_id' => $userId],
        );
        $data['settings_changes'] = $settingsResult->map(static fn(Row $row): array => $row->toArray());

        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            $userId,
            'cms.gdpr.data_exported',
            "user:{$userId}",
            [
                'content_count' => count($data['content']),
                'comment_count' => count($data['comments']),
                'media_count' => count($data['media']),
            ],
        );

        return $data;
    }

    public function eraseUserData(string $userId, string $reason): array
    {
        return $this->connection->transaction(function (ConnectionInterface $conn) use ($userId, $reason): array {
            // Anonymize comments: zero ip_hash, user_agent_hash; redact guest_email
            $commentsAnonymized = $conn->execute(
                "UPDATE cms_comments SET ip_hash = '', user_agent_hash = '', guest_email = CASE WHEN guest_email IS NOT NULL THEN '[redacted]' ELSE NULL END WHERE author_id = :user_id AND deleted_at IS NULL",
                ['user_id' => $userId],
            );

            // Anonymize content: replace author display in any denormalized fields
            // We don't delete content — only PII fields
            $contentAnonymized = $conn->execute(
                "UPDATE cms_contents SET author_id = '[anonymized]' WHERE author_id = :user_id AND deleted_at IS NULL",
                ['user_id' => $userId],
            );

            $this->auditLogger?->log(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                $userId,
                'cms.gdpr.data_erased',
                "user:{$userId}",
                [
                    'reason' => $reason,
                    'comments_anonymized' => $commentsAnonymized,
                    'content_anonymized' => $contentAnonymized,
                ],
            );

            return [
                'comments_anonymized' => $commentsAnonymized,
                'content_anonymized' => $contentAnonymized,
            ];
        });
    }
}
