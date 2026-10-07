<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Vex;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Vex\VexJustification;
use Pulsar\SupplyChain\Vex\VexStatement;
use Pulsar\SupplyChain\Vex\VexStatus;

#[CoversClass(VexStatement::class)]
final class VexStatementTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $stmt = new VexStatement(
            vulnerability: 'CVE-2024-99999',
            status: VexStatus::NotAffected,
            justification: VexJustification::VulnerableCodeNotPresent,
            actionStatement: 'The vulnerable function is not called.',
            product: 'vendor/package',
        );

        self::assertSame('CVE-2024-99999', $stmt->vulnerability);
        self::assertSame(VexStatus::NotAffected, $stmt->status);
        self::assertSame(VexJustification::VulnerableCodeNotPresent, $stmt->justification);
        self::assertSame('The vulnerable function is not called.', $stmt->actionStatement);
        self::assertSame('vendor/package', $stmt->product);
    }

    #[Test]
    public function justificationDefaultsToNull(): void
    {
        $stmt = new VexStatement(
            vulnerability: 'CVE-2024-11111',
            status: VexStatus::Affected,
        );

        self::assertNull($stmt->justification);
    }

    #[Test]
    public function actionStatementDefaultsToEmptyString(): void
    {
        $stmt = new VexStatement(
            vulnerability: 'CVE-2024-22222',
            status: VexStatus::Fixed,
        );

        self::assertSame('', $stmt->actionStatement);
    }

    #[Test]
    public function productDefaultsToEmptyString(): void
    {
        $stmt = new VexStatement(
            vulnerability: 'CVE-2024-33333',
            status: VexStatus::UnderInvestigation,
        );

        self::assertSame('', $stmt->product);
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function allStatusValuesAreAccepted(VexStatus $status): void
    {
        $stmt = new VexStatement(vulnerability: 'CVE-2024-00000', status: $status);

        self::assertSame($status, $stmt->status);
    }

    /**
     * @return iterable<string, array{VexStatus}>
     */
    public static function statusProvider(): iterable
    {
        yield 'not_affected' => [VexStatus::NotAffected];
        yield 'affected' => [VexStatus::Affected];
        yield 'fixed' => [VexStatus::Fixed];
        yield 'under_investigation' => [VexStatus::UnderInvestigation];
    }

    #[Test]
    #[DataProvider('justificationProvider')]
    public function allJustificationValuesAreAccepted(VexJustification $justification): void
    {
        $stmt = new VexStatement(
            vulnerability: 'CVE-2024-00000',
            status: VexStatus::NotAffected,
            justification: $justification,
        );

        self::assertSame($justification, $stmt->justification);
    }

    /**
     * @return iterable<string, array{VexJustification}>
     */
    public static function justificationProvider(): iterable
    {
        yield 'component_not_present' => [VexJustification::ComponentNotPresent];
        yield 'vulnerable_code_not_present' => [VexJustification::VulnerableCodeNotPresent];
        yield 'vulnerable_code_not_in_execute_path' => [VexJustification::VulnerableCodeNotInExecutePath];
        yield 'inline_mitigations_already_exist' => [VexJustification::InlineMitigationsAlreadyExist];
    }
}
