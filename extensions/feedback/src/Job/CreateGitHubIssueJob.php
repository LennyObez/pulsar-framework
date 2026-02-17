<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Job;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;
use Pulsar\Http\Client\HttpClientInterface;
use Throwable;

use function is_array;
use function is_string;
use function json_encode;
use function mb_substr;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Creates a GitHub issue from a feedback item using the GitHub REST API.
 *
 * Builds the issue title (first 80 characters of description), body (full
 * description + context as a JSON code block + feedback ID), and maps
 * the feedback category to GitHub labels.
 *
 * Uses the framework's HttpClientInterface to POST to the GitHub API endpoint
 * `POST /repos/{owner}/{repo}/issues`.
 */
#[Internal(reason: 'Queue job; implementation detail')]
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
        private HttpClientInterface $httpClient,
        private string $feedbackId,
        private string $githubRepo,
        private string $githubToken,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Create a GitHub issue from the feedback item via the GitHub REST API.
     *
     * On success, links the created issue URL back to the feedback entity.
     * On failure, logs the error without re-throwing (queue jobs should not
     * propagate exceptions unless retriable).
     */
    public function handle(): void
    {
        $feedback = $this->repository->findById($this->feedbackId);

        if ($feedback === null) {
            return;
        }

        $title = mb_substr($feedback->description, 0, 80);
        $label = self::CATEGORY_LABELS[$feedback->category->value] ?? 'feedback';

        $contextBlock = $feedback->context !== []
            ? sprintf(
                "\n\n### Context\n\n```json\n%s\n```",
                json_encode($feedback->context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            )
            : '';

        $body = sprintf(
            "## Feedback\n\n%s%s\n\n---\n*Feedback ID: %s*",
            $feedback->description,
            $contextBlock,
            $this->feedbackId,
        );

        $url = sprintf('https://api.github.com/repos/%s/issues', $this->githubRepo);

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->githubToken,
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'title' => $title,
                    'body' => $body,
                    'labels' => [$label],
                ],
            ]);

            if ($response->ok()) {
                $data = $response->json();

                if (is_array($data) && isset($data['html_url']) && is_string($data['html_url'])) {
                    $updated = $feedback->linkGitHubIssue($data['html_url']);
                    $this->repository->save($updated);
                }
            } else {
                $this->logger?->error('GitHub issue creation failed', [
                    'feedback_id' => $this->feedbackId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }
        } catch (Throwable $e) {
            $this->logger?->error('GitHub issue creation error', [
                'feedback_id' => $this->feedbackId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
