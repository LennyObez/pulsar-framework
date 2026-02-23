<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Legal deadline entity.
 *
 * Represents a court deadline, filing due date, or other time-sensitive
 * obligation associated with a case. Missing deadlines can have severe
 * legal consequences.
 */
final class Deadline
{
    /**
     * @param non-empty-string        $id           Unique deadline identifier
     * @param non-empty-string        $caseId       Associated case identifier
     * @param non-empty-string        $title        Deadline description
     * @param non-empty-string        $deadlineType Type (e.g., "filing", "hearing", "discovery", "response")
     * @param DeadlineStatus          $status       Current deadline status
     * @param \DateTimeImmutable      $dueAt        Due date and time
     * @param int                     $reminderDays Days before due date to send reminder
     * @param non-empty-string|null   $assignedTo   Assigned attorney or staff member
     * @param non-empty-string|null   $notes        Additional notes
     * @param \DateTimeImmutable      $createdAt    Creation timestamp
     */
    public function __construct(
        public readonly string $id,
        public readonly string $caseId,
        public readonly string $title,
        public readonly string $deadlineType,
        public DeadlineStatus $status = DeadlineStatus::Pending,
        public readonly \DateTimeImmutable $dueAt = new \DateTimeImmutable(),
        public readonly int $reminderDays = 7,
        public readonly ?string $assignedTo = null,
        public readonly ?string $notes = null,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}

    public function isOverdue(): bool
    {
        return $this->status === DeadlineStatus::Pending
            && $this->dueAt < new \DateTimeImmutable();
    }

    public function needsReminder(): bool
    {
        if ($this->status !== DeadlineStatus::Pending) {
            return false;
        }

        $reminderDate = \DateTimeImmutable::createFromInterface($this->dueAt)
            ->modify(sprintf('-%d days', $this->reminderDays));

        return $reminderDate <= new \DateTimeImmutable() && !$this->isOverdue();
    }

    public function isPending(): bool
    {
        return $this->status === DeadlineStatus::Pending;
    }
}
