<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Internal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Internal\ConsentEnforcingDataGovernance;

#[CoversClass(ConsentEnforcingDataGovernance::class)]
final class ConsentEnforcingDataGovernanceTest extends TestCase
{
    #[Test]
    public function rejectsProvenanceRecordedWithoutConsent(): void
    {
        $inner = $this->createMock(AiDataGovernanceInterface::class);
        $inner->expects(self::never())->method('recordProvenance');

        $governance = new ConsentEnforcingDataGovernance($inner);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('consent is required');

        $governance->recordProvenance($this->provenance(consentObtained: false));
    }

    #[Test]
    public function delegatesProvenanceWhenConsentObtained(): void
    {
        $provenance = $this->provenance(consentObtained: true);

        $inner = $this->createMock(AiDataGovernanceInterface::class);
        $inner->expects(self::once())->method('recordProvenance')->with($provenance);

        new ConsentEnforcingDataGovernance($inner)->recordProvenance($provenance);
    }

    #[Test]
    public function delegatesReadAndQualityOperationsUnchanged(): void
    {
        $inner = $this->createStub(AiDataGovernanceInterface::class);
        $inner->method('isConsentComplete')->willReturn(true);
        $inner->method('getProvenance')->willReturn([]);

        $governance = new ConsentEnforcingDataGovernance($inner);

        self::assertTrue($governance->isConsentComplete('dataset-1'));
        self::assertSame([], $governance->getProvenance('dataset-1'));
    }

    private function provenance(bool $consentObtained): DataProvenance
    {
        return new DataProvenance(
            id: 'prov-1',
            datasetId: 'dataset-1',
            source: 'vendor-feed',
            dataType: 'text',
            collectedAt: new DateTimeImmutable('2026-01-01'),
            consentObtained: $consentObtained,
        );
    }
}
