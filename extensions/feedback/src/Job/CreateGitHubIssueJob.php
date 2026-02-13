<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Job;

use Pulsar\Api\Internal;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;

use function json_encode;
use function mb_substr;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Creates a GitHub issue from a feedback item using the GitHub CLI.
 *
 * Builds the issue title (first 80 characters of description), body (full
 * description + context as a JSON code block + feedback ID), and maps
 * the feedback category to GitHub labels.
 *
 * Currently a stub: handle() references all properties and builds the command
 * parameters without executing them. The real implementation would use
 * proc_open() with explicit argument arrays (no shell injection) for the
 * GitHub CLI `gh issue create` command.
 */
#[Internal(reason: 'Queue job — implementation detail')]
final readonly class CreateGitHubIssueJob
{
    private const array CATEGORY_LABELS = [
        'bug' => 'bug',
        'feature' => 'enhancement',
        'improvement' => 'enhancement',
        'question' => 'question',
        'other' => 'feedback',
    ];

    public function __construct(
        private FeedbackRepositoryInterface $repository,
        private string $feedbackId,
        private string $githubRepo,
    ) {}

    /**
     * Build and prepare the GitHub issue creation.
     *
     * Stub implementation — references all properties and builds the command
     * parameters without executing them. A real implementation would call
     * `gh issue create` via proc_open() with an explicit argument array
     * to prevent shell injection.
     */
    public function handle(): void
    {
        $feedback = $this->repository->findById($this->feedbackId);

        if ($feedback === null) {
            return;
        }

        // Build issue title from first 80 chars of description
        $title = mb_substr($feedback->description, 0, 80);

        // Map category to GitHub label
        $label = self::CATEGORY_LABELS[$feedback->category->value] ?? 'feedback';

        // Build issue body with context
        $contextBlock = $feedback->context !== []
            ? sprintf(
                "\n\n### Context\n\n```json\n%s\n```",
                json_encode($feedback->context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            )
            : '';

        $body = sprintf(
            "## Feedback\n\n%s%s\n\n---\n*Feedback ID: %s*\n*Repository: %s*",
            $feedback->description,
            $contextBlock,
            $this->feedbackId,
            $this->githubRepo,
        );

        $command = [
            'gh', 'issue', 'create',
            '--repo', $this->githubRepo,
            '--title', $title,
            '--body', $body,
            '--label', $label,
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            return;
        }

        $output = is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        is_resource($pipes[1]) && fclose($pipes[1]);
        is_resource($pipes[2]) && fclose($pipes[2]);
        proc_close($process);

        $issueUrl = is_string($output) ? trim($output) : '';

        if ($issueUrl !== '' && str_starts_with($issueUrl, 'https://')) {
            $updated = $feedback->linkGitHubIssue($issueUrl);
            $this->repository->save($updated);
        }
    }
}
