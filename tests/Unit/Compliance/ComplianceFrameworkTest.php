<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;

#[CoversClass(ComplianceFramework::class)]
final class ComplianceFrameworkTest extends TestCase
{
    #[Test]
    public function hasEighteenCases(): void
    {
        self::assertCount(18, ComplianceFramework::cases());
    }

    #[Test]
    #[DataProvider('frameworkProvider')]
    public function backedValues(ComplianceFramework $framework, string $expected): void
    {
        self::assertSame($expected, $framework->value);
    }

    /**
     * @return iterable<string, array{ComplianceFramework, string}>
     */
    public static function frameworkProvider(): iterable
    {
        yield 'Soc2' => [ComplianceFramework::Soc2, 'soc2'];
        yield 'Hipaa' => [ComplianceFramework::Hipaa, 'hipaa'];
        yield 'Gdpr' => [ComplianceFramework::Gdpr, 'gdpr'];
        yield 'PciDss' => [ComplianceFramework::PciDss, 'pci_dss'];
        yield 'Nis2' => [ComplianceFramework::Nis2, 'nis2'];
        yield 'Iso27001' => [ComplianceFramework::Iso27001, 'iso27001'];
        yield 'Psd2' => [ComplianceFramework::Psd2, 'psd2'];
        yield 'Eidas' => [ComplianceFramework::Eidas, 'eidas'];
        yield 'Iso42001' => [ComplianceFramework::Iso42001, 'iso42001'];
        yield 'Hl7Fhir' => [ComplianceFramework::Hl7Fhir, 'hl7_fhir'];
        yield 'Mdr' => [ComplianceFramework::Mdr, 'mdr'];
        yield 'Iso13485' => [ComplianceFramework::Iso13485, 'iso13485'];
        yield 'Dora' => [ComplianceFramework::Dora, 'dora'];
        yield 'SwiftCsp' => [ComplianceFramework::SwiftCsp, 'swift_csp'];
        yield 'Ccpa' => [ComplianceFramework::Ccpa, 'ccpa'];
        yield 'NistCsf' => [ComplianceFramework::NistCsf, 'nist_csf'];
        yield 'Dsa' => [ComplianceFramework::Dsa, 'dsa'];
        yield 'DataAct' => [ComplianceFramework::DataAct, 'data_act'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (ComplianceFramework::cases() as $framework) {
            self::assertSame($framework, ComplianceFramework::from($framework->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(ComplianceFramework::tryFrom('unknown_framework'));
    }

    #[Test]
    public function allValuesAreUniqueSnakeCase(): void
    {
        $values = [];

        foreach (ComplianceFramework::cases() as $framework) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $framework->value);
            self::assertNotContains($framework->value, $values, "Duplicate value: {$framework->value}");
            $values[] = $framework->value;
        }
    }
}
