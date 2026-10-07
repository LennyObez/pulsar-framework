<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Evidence\ControlEvidenceGatherer;
use Pulsar\Compliance\Evidence\EvidenceSourceInterface;
use Pulsar\Core\Wiring\ComplianceCatalogWiring;
use ReflectionClass;

use function file_get_contents;
use function is_string;
use function str_contains;

/**
 * The seam between the composition root and `compliance:report`.
 *
 * The command depends on {@see EvidenceSourceInterface} and is registered in
 * bin/pulsar only when that binding exists. Both halves of that arrangement are
 * invisible at compile time — a gatherer that stopped implementing the
 * interface, or a wiring that stopped binding it, would not break a build. It
 * would do something worse and quieter: the command would silently vanish from
 * `pulsar list`, and a pipeline invoking it would fail with "command not found"
 * rather than with a compliance finding. These assertions make that a test
 * failure instead.
 */
#[CoversClass(EvidenceSourceInterface::class)]
final class EvidenceSourceContractTest extends TestCase
{
    #[Test]
    public function theGathererIsAnEvidenceSource(): void
    {
        self::assertTrue(
            new ReflectionClass(ControlEvidenceGatherer::class)->implementsInterface(EvidenceSourceInterface::class),
            'ControlEvidenceGatherer must implement EvidenceSourceInterface: it is the only '
                . 'production implementation, and compliance:report is guarded on that binding.',
        );
    }

    #[Test]
    public function theCompositionRootBindsTheInterface(): void
    {
        $source = file_get_contents(
            new ReflectionClass(ComplianceCatalogWiring::class)->getFileName() ?: '',
        );

        self::assertTrue(is_string($source));
        self::assertTrue(
            str_contains($source, 'EvidenceSourceInterface::class'),
            'ComplianceCatalogWiring must bind EvidenceSourceInterface, or compliance:report '
                . 'is never registered and the gate cannot run.',
        );
    }

    #[Test]
    public function theEntryPointRegistersTheCommandOnThatBinding(): void
    {
        $binary = file_get_contents(__DIR__ . '/../../../../bin/pulsar');

        self::assertTrue(is_string($binary));
        self::assertTrue(
            str_contains($binary, 'ComplianceReportCommand')
                && str_contains($binary, 'EvidenceSourceInterface::class'),
            'bin/pulsar must register ComplianceReportCommand, guarded on the evidence source.',
        );
    }
}
