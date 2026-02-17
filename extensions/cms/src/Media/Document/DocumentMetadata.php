<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Document;

use Pulsar\Api\Api;

use function array_filter;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Structured metadata extracted from a PDF or document file.
 */
#[Api(since: '1.0.0')]
final readonly class DocumentMetadata
{
    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        public ?string $subject = null,
        public ?string $creator = null,
        public ?string $producer = null,
        public ?int $pageCount = null,
        public ?string $creationDate = null,
        public ?string $modificationDate = null,
        public ?string $pdfVersion = null,
        public ?int $fileSize = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: is_string($data['title'] ?? null) ? $data['title'] : null,
            author: is_string($data['author'] ?? null) ? $data['author'] : null,
            subject: is_string($data['subject'] ?? null) ? $data['subject'] : null,
            creator: is_string($data['creator'] ?? null) ? $data['creator'] : null,
            producer: is_string($data['producer'] ?? null) ? $data['producer'] : null,
            pageCount: is_int($data['page_count'] ?? null) ? $data['page_count'] : null,
            creationDate: is_string($data['creation_date'] ?? null) ? $data['creation_date'] : null,
            modificationDate: is_string($data['modification_date'] ?? null) ? $data['modification_date'] : null,
            pdfVersion: is_string($data['pdf_version'] ?? null) ? $data['pdf_version'] : null,
            fileSize: is_int($data['file_size'] ?? null) ? $data['file_size'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'author' => $this->author,
            'subject' => $this->subject,
            'creator' => $this->creator,
            'producer' => $this->producer,
            'page_count' => $this->pageCount,
            'creation_date' => $this->creationDate,
            'modification_date' => $this->modificationDate,
            'pdf_version' => $this->pdfVersion,
            'file_size' => $this->fileSize,
        ], static fn(mixed $v): bool => $v !== null);
    }

    public function getDisplayTitle(): string
    {
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        return 'Untitled Document';
    }

    public function getPageSummary(): ?string
    {
        if ($this->pageCount === null) {
            return null;
        }

        return sprintf('%d %s', $this->pageCount, $this->pageCount === 1 ? 'page' : 'pages');
    }
}
