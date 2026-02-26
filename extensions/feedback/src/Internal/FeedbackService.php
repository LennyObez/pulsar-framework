<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Internal;

use InvalidArgumentException;
use OverflowException;
use Pulsar\Api\Internal;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;
use Pulsar\Extension\Feedback\FeedbackStatus;

use function mb_strlen;

/**
 * Core feedback domain service handling submission, triage, and linking.
 *
 * Enforces business rules:
 * - Description length between 10 and 5000 characters
 * - Maximum 10 submissions per user per day (rate limiting)
 */
#[Internal(reason: 'Feedback domain service — use FeedbackRepositoryInterface for public API')]
final readonly class FeedbackService
{
    private const int MIN_DESCRIPTION_LENGTH = 10;
    private const int MAX_DESCRIPTION_LENGTH = 5000;
    private const int MAX_SUBMISSIONS_PER_DAY = 10;

    public function __construct(
        private FeedbackRepositoryInterface $repository,
    ) {}

    /**
     * Submit new feedback from a user.
     *
     * @param array<string, mixed> $context
     *
     * @throws InvalidArgumentException If description is outside allowed length
     * @throws OverflowException If user has exceeded the daily rate limit
     */
    public function submit(
        string $userId,
        FeedbackCategory $category,
        string $description,
        array $context,
    ): Feedback {
        $length = mb_strlen($description);

        if ($length < self::MIN_DESCRIPTION_LENGTH || $length > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException(
                'Description must be between '
                . self::MIN_DESCRIPTION_LENGTH
                . ' and '
                . self::MAX_DESCRIPTION_LENGTH
                . ' characters',
            );
        }

        $todayCount = $this->repository->countByUserToday($userId);

        if ($todayCount >= self::MAX_SUBMISSIONS_PER_DAY) {
            throw new OverflowException(
                'Rate limit exceeded: maximum '
                . self::MAX_SUBMISSIONS_PER_DAY
                . ' feedback submissions per day',
            );
        }

        $feedback = Feedback::create($userId, $category, $description, $context);
        $this->repository->save($feedback);

        return $feedback;
    }

    /**
     * Transition a feedback item to a new status.
     */
    public function updateStatus(string $feedbackId, FeedbackStatus $status): ?Feedback
    {
        $feedback = $this->repository->findById($feedbackId);

        if ($feedback === null) {
            return null;
        }

        $updated = $feedback->updateStatus($status);
        $this->repository->save($updated);

        return $updated;
    }

    /**
     * Attach an admin response to a feedback item.
     */
    public function addResponse(string $feedbackId, string $response): ?Feedback
    {
        $feedback = $this->repository->findById($feedbackId);

        if ($feedback === null) {
            return null;
        }

        $updated = $feedback->addAdminResponse($response);
        $this->repository->save($updated);

        return $updated;
    }

    /**
     * Link a feedback item to a GitHub issue URL.
     */
    public function linkIssue(string $feedbackId, string $url): ?Feedback
    {
        $feedback = $this->repository->findById($feedbackId);

        if ($feedback === null) {
            return null;
        }

        $updated = $feedback->linkGitHubIssue($url);
        $this->repository->save($updated);

        return $updated;
    }
}
