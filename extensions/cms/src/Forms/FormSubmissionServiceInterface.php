<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Forms;

use Pulsar\Api\Api;

/**
 * Service contract for form submission processing.
 */
#[Api(since: '1.0.0')]
interface FormSubmissionServiceInterface
{
    /**
     * Process a form submission with spam detection and notifications.
     *
     * @param array<string, mixed> $formData
     * @param array<string, mixed> $meta Request metadata (IP, user agent, CSRF token, etc.)
     */
    public function submit(array $formData, array $meta): FormSubmission;

    public function markAsRead(string $id, ?string $tenantId = null): void;

    public function markAsSpam(string $id, string $reason, ?string $tenantId = null): void;

    /**
     * Export all submissions for a content item as CSV.
     */
    public function exportSubmissions(string $contentId, ?string $tenantId = null): string;
}
