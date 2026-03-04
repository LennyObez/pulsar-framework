<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\Document\DocumentMetadata;

#[CoversClass(DocumentMetadata::class)]
final class DocumentMetadataTest extends TestCase
{
    #[Test]
    public function defaultConstructorHasAllNulls(): void
    {
        $meta = new DocumentMetadata();

        self::assertNull($meta->title);
        self::assertNull($meta->author);
        self::assertNull($meta->subject);
        self::assertNull($meta->creator);
        self::assertNull($meta->producer);
        self::assertNull($meta->pageCount);
        self::assertNull($meta->creationDate);
        self::assertNull($meta->modificationDate);
        self::assertNull($meta->pdfVersion);
        self::assertNull($meta->fileSize);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $meta = DocumentMetadata::fromArray([
            'title' => 'Test Doc',
            'author' => 'John Doe',
            'subject' => 'Testing',
            'creator' => 'LibreOffice',
            'producer' => 'PDF Creator',
            'page_count' => 42,
            'creation_date' => '2024-01-15',
            'modification_date' => '2024-06-20',
            'pdf_version' => '1.7',
            'file_size' => 1048576,
        ]);

        self::assertSame('Test Doc', $meta->title);
        self::assertSame('John Doe', $meta->author);
        self::assertSame('Testing', $meta->subject);
        self::assertSame('LibreOffice', $meta->creator);
        self::assertSame('PDF Creator', $meta->producer);
        self::assertSame(42, $meta->pageCount);
        self::assertSame('2024-01-15', $meta->creationDate);
        self::assertSame('2024-06-20', $meta->modificationDate);
        self::assertSame('1.7', $meta->pdfVersion);
        self::assertSame(1048576, $meta->fileSize);
    }

    #[Test]
    public function toArrayExcludesNulls(): void
    {
        $meta = new DocumentMetadata(title: 'Hello', pageCount: 5);

        $array = $meta->toArray();

        self::assertSame(['title' => 'Hello', 'page_count' => 5], $array);
    }

    #[Test]
    public function getDisplayTitleReturnsTitle(): void
    {
        $meta = new DocumentMetadata(title: 'My Document');

        self::assertSame('My Document', $meta->getDisplayTitle());
    }

    #[Test]
    public function getDisplayTitleReturnsFallbackWhenEmpty(): void
    {
        $meta = new DocumentMetadata();

        self::assertSame('Untitled Document', $meta->getDisplayTitle());
    }

    #[Test]
    public function getDisplayTitleReturnsFallbackForEmptyString(): void
    {
        $meta = new DocumentMetadata(title: '');

        self::assertSame('Untitled Document', $meta->getDisplayTitle());
    }

    #[Test]
    #[DataProvider('pageSummaryProvider')]
    public function getPageSummaryFormatsCorrectly(?int $pageCount, ?string $expected): void
    {
        $meta = new DocumentMetadata(pageCount: $pageCount);

        self::assertSame($expected, $meta->getPageSummary());
    }

    /**
     * @return iterable<string, array{?int, ?string}>
     */
    public static function pageSummaryProvider(): iterable
    {
        yield 'null' => [null, null];
        yield 'single page' => [1, '1 page'];
        yield 'multiple pages' => [42, '42 pages'];
        yield 'zero pages' => [0, '0 pages'];
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $meta = DocumentMetadata::fromArray([
            'title' => 123,
            'page_count' => 'not_a_number',
            'file_size' => true,
        ]);

        self::assertNull($meta->title);
        self::assertNull($meta->pageCount);
        self::assertNull($meta->fileSize);
    }
}
