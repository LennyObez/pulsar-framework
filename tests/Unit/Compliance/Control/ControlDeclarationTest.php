<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Control;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlProbeInterface;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Probe\PanAtRestProbe;
use ReflectionClass;
use ReflectionNamedType;

use function array_map;
use function str_starts_with;

/**
 * What a mapping is allowed to write down.
 *
 * The decision this whole change turns on is expressed as a type: there is no
 * status parameter anywhere in the declaration API, so "Implemented" is not a
 * thing a mapping file can say. These tests assert that as a property of the
 * class rather than as a convention people are asked to keep.
 */
#[CoversClass(ControlDeclaration::class)]
final class ControlDeclarationTest extends TestCase
{
    /**
     * No named constructor, and no constructor at all, accepts an outcome, a
     * status or anything shaped like one. Asserted by reflection so that adding
     * such a parameter fails the build rather than passing review.
     */
    #[Test]
    public function noConstructorAcceptsAStatusOfAnyKind(): void
    {
        $reflection = new ReflectionClass(ControlDeclaration::class);

        self::assertFalse(
            $reflection->getConstructor()?->isPublic() ?? false,
            'The constructor must stay private; a public one is a second way to build a control.',
        );

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $name = $type instanceof ReflectionNamedType ? $type->getName() : '';

                self::assertStringNotContainsStringIgnoringCase(
                    'status',
                    $parameter->getName(),
                    $method->getName() . '() takes a status-shaped parameter.',
                );
                self::assertStringNotContainsStringIgnoringCase(
                    'outcome',
                    $name,
                    $method->getName() . '() takes an outcome, which only a probe may produce.',
                );
            }
        }
    }

    /**
     * Every public factory is one of exactly two, and each fixes what its control
     * may rest on: a probe, or a named artefact. There is no third shape.
     */
    #[Test]
    public function thereAreExactlyTwoWaysToDeclareAControl(): void
    {
        $factories = array_map(
            static fn(object $method): string => $method->getName(),
            array_filter(
                new ReflectionClass(ControlDeclaration::class)->getMethods(),
                // `__set_state` is excluded because it is a language hook rather
                // than a factory: it exists only to REFUSE the var_export round trip
                // that would otherwise rebuild a declaration without a constructor.
                static fn(object $method): bool => $method->isStatic()
                    && $method->isPublic()
                    && !str_starts_with($method->getName(), '__'),
            ),
        );

        self::assertEqualsCanonicalizing(['probed', 'operatorResponsibility'], array_values($factories));
    }

    #[Test]
    public function aProbedControlCarriesItsProbeAndNoArtefact(): void
    {
        $declaration = ControlDeclaration::probed(
            id: 'Req3.4',
            framework: ComplianceFramework::PciDss,
            title: 'Render PAN Unreadable',
            requirement: 'Render PAN unreadable anywhere it is stored.',
            probe: new PanAtRestProbe(),
            subject: ControlSubject::CardholderData,
        );

        self::assertTrue($declaration->isProbed());
        self::assertInstanceOf(ControlProbeInterface::class, $declaration->probe);
        self::assertSame('', $declaration->operatorArtefact);
    }

    /**
     * A control discharged outside the software carries no probe at all — the type
     * says so — and must name what an assessor should be shown instead.
     */
    #[Test]
    public function anOperatorResponsibilityCarriesItsArtefactAndNoProbe(): void
    {
        $declaration = ControlDeclaration::operatorResponsibility(
            id: 'A.5.1',
            framework: ComplianceFramework::Iso27001,
            title: 'Policies for Information Security',
            requirement: 'An information security policy shall be defined and approved.',
            artefact: 'The approved information security policy set.',
        );

        self::assertFalse($declaration->isProbed());
        self::assertNull($declaration->probe);
        self::assertSame('The approved information security policy set.', $declaration->operatorArtefact);
    }
}
