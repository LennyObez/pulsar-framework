<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\Repair;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\Repair\RepairDiagnosis;

#[CoversClass(RepairDiagnosis::class)]
final class RepairDiagnosisTest extends TestCase
{
    #[Test]
    public function holdsAllProperties(): void
    {
        $diagnosis = new RepairDiagnosis(
            repairJobName: 'cache-rebuild',
            needsRepair: true,
            description: 'Cache index corrupted',
            findings: ['Missing key: user.42', 'Stale entry: session.abc'],
        );

        self::assertSame('cache-rebuild', $diagnosis->repairJobName);
        self::assertTrue($diagnosis->needsRepair);
        self::assertSame('Cache index corrupted', $diagnosis->description);
        self::assertCount(2, $diagnosis->findings);
    }

    #[Test]
    public function defaultEmptyFindings(): void
    {
        $diagnosis = new RepairDiagnosis('cleanup', false, 'All clear');

        self::assertSame([], $diagnosis->findings);
        self::assertFalse($diagnosis->needsRepair);
    }
}
