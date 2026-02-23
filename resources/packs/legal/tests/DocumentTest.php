<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use {{namespace}}\Entity\Document;
use {{namespace}}\Entity\DocumentStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentTest extends TestCase
{
    #[Test]
    public function it_creates_a_non_privileged_document(): void
    {
        $document = new Document(
            id: 'doc_001',
            caseId: 'case_001',
            title: 'Motion to Dismiss',
            category: 'court_filings',
            filePath: '/documents/doc_001.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 102400,
        );

        self::assertSame('doc_001', $document->id);
        self::assertFalse($document->isPrivileged());
        self::assertFalse($document->isUnderLitigationHold());
    }

    #[Test]
    public function it_identifies_privileged_documents(): void
    {
        $document = new Document(
            id: 'doc_002',
            caseId: 'case_001',
            title: 'Attorney Work Product',
            category: 'client_correspondence',
            filePath: '/documents/doc_002.pdf',
            mimeType: 'application/pdf',
            privileged: true,
            privilegeReason: 'Attorney-client communication',
        );

        self::assertTrue($document->isPrivileged());
    }

    #[Test]
    public function it_prevents_destruction_under_litigation_hold(): void
    {
        $document = new Document(
            id: 'doc_003',
            caseId: 'case_001',
            title: 'Evidence Document',
            category: 'court_filings',
            filePath: '/documents/doc_003.pdf',
            mimeType: 'application/pdf',
            litigationHold: true,
            retainUntil: new \DateTimeImmutable('-1 year'),
        );

        self::assertFalse($document->canBeDestroyed());
    }

    // TODO: Add tests for retention policy and document lifecycle
}
