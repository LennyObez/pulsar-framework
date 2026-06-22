<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Forms;

use Pulsar\Api\Api;

/**
 * Service contract for form submission processing.
 *
 * @psalm-api Public binding contract; implemented by FormSubmissionService
 *            and consumed by public form controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface FormSubmissionServiceInterface
{
    /**
     * Process a form submission with spam detection and notifications.
     *
     * @param array<string, mixed> $formData
     * @param array{
     *     _csrf_token?: string,
     *     ip?: string,
     *     user_agent?: string,
     *     form_block_id?: string,
     *     content_id?: string,
     *     tenant_id?: string|null,
     *     _hp_field?: string,
     *     _pow_nonce?: string,
     *     _pow_challenge?: string,
     *     _form_rendered_at?: mixed,
     * } $meta Request metadata: identity (IP, user agent, CSRF token) plus the
     *         anti-spam signals (honeypot, proof-of-work, render timestamp) the
     *         spam detectors consume.
     */
    public function submit(array $formData, array $meta): FormSubmission;

    public function markAsRead(string $id, ?string $tenantId = null): void;

    public function markAsSpam(string $id, string $reason, ?string $tenantId = null): void;

    /**
     * Export all submissions for a content item as CSV.
     */
    public function exportSubmissions(string $contentId, ?string $tenantId = null): string;
}
