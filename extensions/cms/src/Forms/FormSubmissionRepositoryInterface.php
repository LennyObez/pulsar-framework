<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Forms;

use Pulsar\Api\Api;

/**
 * Repository contract for form submission persistence.
 *
 * @psalm-api Public binding contract; implemented by DbFormSubmissionRepository
 *            and consumed by FormSubmissionService and admin controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface FormSubmissionRepositoryInterface
{
    public function save(FormSubmission $submission): void;

    public function findById(string $id, ?string $tenantId = null): ?FormSubmission;

    /**
     * @return list<FormSubmission>
     */
    public function findByContentId(string $contentId, ?string $tenantId = null): array;

    /**
     * @return list<FormSubmission>
     */
    public function findByTenantId(string $tenantId): array;

    public function markAsRead(string $id): void;

    public function markAsSpam(string $id, string $reason): void;

    public function delete(string $id): void;

    public function countUnread(?string $tenantId = null): int;

    /**
     * @return list<FormSubmission>
     */
    public function findAll(
        ?string $tenantId = null,
        bool $includeSpam = false,
        int $limit = 50,
        int $offset = 0,
        ?bool $unreadOnly = null,
    ): array;
}
