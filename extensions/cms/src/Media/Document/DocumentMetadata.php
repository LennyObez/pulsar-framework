<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Document;

use Pulsar\Api\Api;

use function array_filter;
use function sprintf;

/**
 * Structured metadata extracted from a PDF or document file.
 *
 * @psalm-api Public DTO returned from DocumentMetadataExtractor; consumed by
 *            media services and admin views.
 * @api
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
     * @param array{
     *     title?: string|null,
     *     author?: string|null,
     *     subject?: string|null,
     *     creator?: string|null,
     *     producer?: string|null,
     *     page_count?: int|null,
     *     creation_date?: string|null,
     *     modification_date?: string|null,
     *     pdf_version?: string|null,
     *     file_size?: int|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'] ?? null,
            author: $data['author'] ?? null,
            subject: $data['subject'] ?? null,
            creator: $data['creator'] ?? null,
            producer: $data['producer'] ?? null,
            pageCount: $data['page_count'] ?? null,
            creationDate: $data['creation_date'] ?? null,
            modificationDate: $data['modification_date'] ?? null,
            pdfVersion: $data['pdf_version'] ?? null,
            fileSize: $data['file_size'] ?? null,
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
