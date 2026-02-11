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
            'SELECT id, filename, mime_type, file_size, visibility, created_at FROM cms_media_assets WHERE uploader_id = :user_id AND deleted_at IS NULL',
            ['user_id' => $userId],
        );
        $data['media'] = $mediaResult->map(static fn(Row $row): array => $row->toArray());

        // Customer profiles
        $customerResult = $this->connection->query(
            'SELECT id, email, display_name, billing_address, shipping_address, created_at, updated_at FROM cms_customers WHERE user_id = :user_id',
            ['user_id' => $userId],
        );
        $data['customers'] = $customerResult->map(static fn(Row $row): array => $row->toArray());

        // Orders placed by user's customer accounts
        $orderResult = $this->connection->query(
            'SELECT o.id, o.order_number, o.customer_email, o.status, o.subtotal, o.total, o.currency, o.billing_address, o.shipping_address, o.created_at FROM cms_orders o WHERE o.customer_id IN (SELECT id FROM cms_customers WHERE user_id = :user_id) ORDER BY o.created_at DESC',
            ['user_id' => $userId],
        );
        $data['orders'] = $orderResult->map(static fn(Row $row): array => $row->toArray());

        // Editorial reviews authored by user
        $reviewResult = $this->connection->query(
            'SELECT id, content_id, status, decision, reviewer_notes, created_at FROM cms_editorial_reviews WHERE reviewer_id = :user_id ORDER BY created_at DESC',
            ['user_id' => $userId],
        );
        $data['editorial_reviews'] = $reviewResult->map(static fn(Row $row): array => $row->toArray());

        // Content revisions authored by user
        $revisionResult = $this->connection->query(
            'SELECT id, content_id, created_at FROM cms_content_revisions WHERE author_id = :user_id',
            ['user_id' => $userId],
        );
        $data['content_revisions'] = $revisionResult->map(static fn(Row $row): array => $row->toArray());

        // API keys owned by user
        $apiKeyResult = $this->connection->query(
            'SELECT id, name, created_at FROM cms_api_keys WHERE tenant_id = :user_id',
            ['user_id' => $userId],
        );
        $data['api_keys'] = $apiKeyResult->map(static fn(Row $row): array => $row->toArray());

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

    /**
     * @return array{comments_anonymized: int, content_anonymized: int, reviews_anonymized: int, media_anonymized: int, customers_redacted: int, orders_redacted: int, revisions_anonymized: int, api_keys_anonymized: int, settings_history_anonymized: int}
     */
    public function eraseUserData(string $userId, string $reason): array
    {
        return $this->connection->transaction(function (ConnectionInterface $conn) use ($userId, $reason): array {
            $result = [];

            // Anonymize comments: zero PII hashes and redact guest_email
            $result['comments_anonymized'] = $conn->execute(
                "UPDATE cms_comments SET ip_hash = '', user_agent_hash = '', guest_email = CASE WHEN guest_email IS NOT NULL THEN '[redacted]' ELSE NULL END WHERE author_id = :user_id AND deleted_at IS NULL",
                ['user_id' => $userId],
            );

            // Anonymize content authorship (keep content itself — only PII fields)
            $result['content_anonymized'] = $conn->execute(
                "UPDATE cms_contents SET author_id = '[anonymized]' WHERE author_id = :user_id AND deleted_at IS NULL",
                ['user_id' => $userId],
            );

            // Anonymize editorial reviews
            $result['reviews_anonymized'] = $conn->execute(
                "UPDATE cms_editorial_reviews SET reviewer_id = '[anonymized]' WHERE reviewer_id = :user_id",
                ['user_id' => $userId],
            );

            // Anonymize media uploads (keep files, remove uploader link)
            $result['media_anonymized'] = $conn->execute(
                "UPDATE cms_media_assets SET uploader_id = '[anonymized]' WHERE uploader_id = :user_id AND deleted_at IS NULL",
                ['user_id' => $userId],
            );

            // Redact customer PII (email, addresses, display name)
            $result['customers_redacted'] = $conn->execute(
                "UPDATE cms_customers SET email = '[redacted]', display_name = NULL, billing_address = NULL, shipping_address = NULL WHERE user_id = :user_id",
                ['user_id' => $userId],
            );

            // Redact order PII (customer_email, billing/shipping addresses, notes)
            $result['orders_redacted'] = $conn->execute(
                "UPDATE cms_orders SET customer_email = '[redacted]', billing_address = '{}', shipping_address = NULL, notes = NULL WHERE customer_id IN (SELECT id FROM cms_customers WHERE user_id = :user_id)",
                ['user_id' => $userId],
            );

            // Anonymize content revisions
            $result['revisions_anonymized'] = $conn->execute(
                'UPDATE cms_content_revisions SET author_id = :anon WHERE author_id = :user_id',
                ['anon' => '[anonymized]', 'user_id' => $userId],
            );

            // Deactivate and anonymize API keys
            $result['api_keys_anonymized'] = $conn->execute(
                'UPDATE cms_api_keys SET name = :anon, is_active = 0 WHERE tenant_id = :user_id',
                ['anon' => '[anonymized]', 'user_id' => $userId],
            );

            // Anonymize settings change history
            $result['settings_history_anonymized'] = $conn->execute(
                "UPDATE cms_settings_history SET changed_by = '[anonymized]' WHERE changed_by = :user_id",
                ['user_id' => $userId],
            );

            $this->auditLogger?->log(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                $userId,
                'cms.gdpr.data_erased',
                "user:{$userId}",
                ['reason' => $reason, ...$result],
            );

            return $result;
        });
    }
}
