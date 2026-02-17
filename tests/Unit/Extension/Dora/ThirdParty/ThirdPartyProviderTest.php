<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Dora\ThirdParty;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\ThirdParty\ThirdPartyProvider;
use Pulsar\Extension\Dora\ThirdParty\ThirdPartyRiskLevel;

#[CoversClass(ThirdPartyProvider::class)]
final class ThirdPartyProviderTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $provider = new ThirdPartyProvider(
            id: 'TPP-001',
            name: 'Cloud Provider Inc.',
            jurisdiction: 'EU',
            riskLevel: ThirdPartyRiskLevel::High,
            supportsCriticalFunctions: true,
        );

        self::assertSame('TPP-001', $provider->id);
        self::assertTrue($provider->supportsCriticalFunctions);
        self::assertSame([], $provider->servicesProvided);
        self::assertNull($provider->exitStrategy);
    }

    #[Test]
    public function toArrayOmitsNullFields(): void
    {
        $provider = new ThirdPartyProvider(
            id: 'TPP-001',
            name: 'Provider',
            jurisdiction: 'US',
            riskLevel: ThirdPartyRiskLevel::Low,
            supportsCriticalFunctions: false,
        );

        $data = $provider->toArray();

        self::assertSame('low', $data['risk_level']);
        self::assertFalse($data['supports_critical_functions']);
        self::assertArrayNotHasKey('services_provided', $data);
        self::assertArrayNotHasKey('exit_strategy', $data);
        self::assertArrayNotHasKey('subcontractors', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $provider = new ThirdPartyProvider(
            id: 'TPP-002',
            name: 'Major Cloud Provider',
            jurisdiction: 'EU',
            riskLevel: ThirdPartyRiskLevel::Critical,
            supportsCriticalFunctions: true,
            servicesProvided: ['Compute', 'Storage', 'Database'],
            contractStartDate: new DateTimeImmutable('2024-01-01'),
            contractEndDate: new DateTimeImmutable('2027-12-31'),
            lastAuditDate: new DateTimeImmutable('2025-01-15'),
            exitStrategy: 'Multi-cloud migration plan documented',
            subcontractors: ['Network Provider Co.', 'Data Center Corp.'],
            dataProcessingLocation: 'Frankfurt, Germany',
        );

        /** @var array<string, mixed> $data */
        $data = $provider->toArray();

        self::assertSame('critical', $data['risk_level']);
        /** @var list<string> $services */
        $services = $data['services_provided'];
        self::assertCount(3, $services);
        self::assertSame('2024-01-01', $data['contract_start_date']);
        self::assertSame('2027-12-31', $data['contract_end_date']);
        self::assertSame('2025-01-15', $data['last_audit_date']);
        self::assertSame('Multi-cloud migration plan documented', $data['exit_strategy']);
        /** @var list<string> $subcontractors */
        $subcontractors = $data['subcontractors'];
        self::assertCount(2, $subcontractors);
        self::assertSame('Frankfurt, Germany', $data['data_processing_location']);
    }
}
