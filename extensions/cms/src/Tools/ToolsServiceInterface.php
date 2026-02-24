<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Service interface for CMS administrative tools.
 *
 * Provides GDPR compliance operations: full data export and
 * PII erasure for a given user, with audit trail.
 */
#[Api(since: '1.0.0')]
interface ToolsServiceInterface
{
    /**
     * Export all CMS data associated with a user.
     *
     * Returns all content authored by, comments by, media uploaded by,
     * and settings changed by the given user as a structured array.
     *
     * @return array<string, mixed> Keyed by data category
     */
    public function exportUserData(string $userId): array;

    /**
     * Erase PII for a given user (GDPR right-to-erasure).
     *
     * Anonymizes: guest_email → [redacted], zeroes ip_hash and
     * user_agent_hash in comments, anonymizes display names in
     * content attribution. Does NOT delete content — only PII.
     *
     * @return array{comments_anonymized: int, content_anonymized: int, reviews_anonymized: int, media_anonymized: int, customers_redacted: int, orders_redacted: int, revisions_anonymized: int, api_keys_anonymized: int, settings_history_anonymized: int}
     */
    public function eraseUserData(string $userId, string $reason): array;
}
