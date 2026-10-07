<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

use DateTimeImmutable;

/**
 * Legal document entity.
 *
 * Represents a document associated with a case. Supports attorney-client
 * privilege flagging and document retention policy enforcement.
 */
final class Document
{
    /**
     * @param non-empty-string        $id              Unique document identifier
     * @param non-empty-string        $caseId          Associated case identifier
     * @param non-empty-string        $title           Document title
     * @param non-empty-string        $category        Document category (for retention rules)
     * @param non-empty-string        $filePath        Storage path reference
     * @param non-empty-string        $mimeType        MIME type of the document
     * @param int                     $sizeBytes       File size in bytes
     * @param bool                    $privileged      Attorney-client privilege flag
     * @param non-empty-string|null   $privilegeReason Reason for privilege assertion
     * @param bool                    $litigationHold  Whether document is under litigation hold
     * @param DocumentStatus          $status          Current document status
     * @param DateTimeImmutable      $createdAt       Creation timestamp
     * @param DateTimeImmutable|null $retainUntil     Retention period end date
     */
    public function __construct(
        public readonly string $id,
        public readonly string $caseId,
        public readonly string $title,
        public readonly string $category,
        public readonly string $filePath,
        public readonly string $mimeType,
        public readonly int $sizeBytes = 0,
        public readonly bool $privileged = false,
        public readonly ?string $privilegeReason = null,
        public bool $litigationHold = false,
        public DocumentStatus $status = DocumentStatus::Active,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public readonly ?DateTimeImmutable $retainUntil = null,
    ) {}

    public function isPrivileged(): bool
    {
        return $this->privileged;
    }

    public function canBeDestroyed(): bool
    {
        if ($this->litigationHold) {
            return false;
        }

        if ($this->retainUntil === null) {
            return false;
        }

        return $this->retainUntil < new DateTimeImmutable();
    }

    public function isUnderLitigationHold(): bool
    {
        return $this->litigationHold;
    }
}
