<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use {{namespace}}\Entity\CaseStatus;
use {{namespace}}\Entity\LegalCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegalCase::class)]
final class LegalCaseTest extends TestCase
{
    #[Test]
    public function it_creates_an_open_case(): void
    {
        $case = new LegalCase(
            id: 'case_001',
            caseNumber: 'CASE-2025-001',
            title: 'Smith v. Jones',
            clientId: 'client_001',
            practiceArea: 'litigation',
        );

        self::assertSame('case_001', $case->id);
        self::assertTrue($case->isOpen());
        self::assertFalse($case->isClosed());
    }

    // TODO: Add tests for case lifecycle, status transitions, and deadline management
}
