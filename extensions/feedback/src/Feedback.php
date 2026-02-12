<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function bin2hex;
use function random_bytes;

/**
 * Immutable entity representing a user feedback submission.
 *
 * Supports lifecycle transitions (status updates, admin responses, GitHub
 * issue linking) via clone-with semantics — each mutation returns a new
 * instance, preserving the original.
 */
#[Api(since: '1.0.0')]
final readonly class Feedback
{
    /**
     * @param string $id Hex-encoded random identifier (32 chars)
     * @param string $userId FK auth_users — the submitting user
     * @param FeedbackCategory $category Classification of the feedback
     * @param string $description User-provided feedback text (10-5000 chars)
     * @param array<string, mixed> $context Arbitrary contextual data (page URL, browser, etc.)
     * @param FeedbackStatus $status Current triage status
     * @param string|null $adminResponse Admin reply visible to the user
     * @param string|null $githubIssueUrl URL to linked GitHub issue
     * @param DateTimeImmutable $createdAt Submission timestamp
     * @param DateTimeImmutable $updatedAt Last modification timestamp
     */
    public function __construct(
        public string $id,
        public string $userId,
        public FeedbackCategory $category,
        public string $description,
        public array $context,
        public FeedbackStatus $status,
        public ?string $adminResponse,
        public ?string $githubIssueUrl,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a new feedback submission with default status and timestamps.
     *
     * @param array<string, mixed> $context
     */
    public static function create(
        string $userId,
        FeedbackCategory $category,
        string $description,
        array $context = [],
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: bin2hex(random_bytes(16)),
            userId: $userId,
            category: $category,
            description: $description,
            context: $context,
            status: FeedbackStatus::Received,
            adminResponse: null,
            githubIssueUrl: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Transition to a new triage status.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function updateStatus(FeedbackStatus $status): self
    {
        return clone($this, [
            'status' => $status,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Attach an admin response visible to the submitting user.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function addAdminResponse(string $response): self
    {
        return clone($this, [
            'adminResponse' => $response,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Link this feedback to a GitHub issue for tracking.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function linkGitHubIssue(string $url): self
    {
        return clone($this, [
            'githubIssueUrl' => $url,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }
}
