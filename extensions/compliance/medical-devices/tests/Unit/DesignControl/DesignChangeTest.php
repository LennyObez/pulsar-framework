<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\DesignControl;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\DesignControl\DesignChange;

#[CoversClass(DesignChange::class)]
final class DesignChangeTest extends TestCase
{
    #[Test]
    public function minimalConstructorSetsDefaults(): void
    {
        $change = new DesignChange(
            changeId: 'DC-001',
            description: 'Replace sensor module',
            justification: 'Improved accuracy',
            requestedAt: new DateTimeImmutable('2026-03-01'),
        );

        self::assertSame('DC-001', $change->changeId);
        self::assertNull($change->approvedAt);
        self::assertNull($change->approvedBy);
        self::assertNull($change->impactAssessment);
        self::assertFalse($change->requiresRevalidation);
    }

    #[Test]
    public function toArrayWithMinimalFields(): void
    {
        $change = new DesignChange(
            changeId: 'DC-002',
            description: 'Update firmware',
            justification: 'Bug fix',
            requestedAt: new DateTimeImmutable('2026-04-15'),
        );

        $array = $change->toArray();

        self::assertSame('DC-002', $array['change_id']);
        self::assertSame('Update firmware', $array['description']);
        self::assertSame('Bug fix', $array['justification']);
        self::assertSame('2026-04-15', $array['requested_at']);
        self::assertFalse($array['requires_revalidation']);
        self::assertArrayNotHasKey('approved_at', $array);
        self::assertArrayNotHasKey('approved_by', $array);
        self::assertArrayNotHasKey('impact_assessment', $array);
    }

    #[Test]
    public function toArrayWithAllFields(): void
    {
        $change = new DesignChange(
            changeId: 'DC-003',
            description: 'Material change',
            justification: 'Biocompatibility improvement',
            requestedAt: new DateTimeImmutable('2026-01-20'),
            approvedAt: new DateTimeImmutable('2026-02-05'),
            approvedBy: 'Dr. Smith',
            impactAssessment: 'Full biocompatibility retest required',
            requiresRevalidation: true,
        );

        $array = $change->toArray();

        self::assertSame('2026-02-05', $array['approved_at']);
        self::assertSame('Dr. Smith', $array['approved_by']);
        self::assertSame('Full biocompatibility retest required', $array['impact_assessment']);
        self::assertTrue($array['requires_revalidation']);
    }
}
