<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\ConsentManagementProbe;
use Pulsar\Compliance\Probe\DataErasureProbe;
use Pulsar\Compliance\Probe\DataProtectionAtRestProbe;
use Pulsar\Compliance\Probe\DataRetentionProbe;
use Pulsar\Compliance\Probe\SubjectRightsProbe;

/**
 * Declares the CCPA/CPRA consumer-rights controls Pulsar can be assessed against.
 *
 * The three consumer-rights controls are gated on the operator's assertion about
 * personal data, so a deployment that genuinely holds none is scoped out on the
 * record rather than silently.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class CcpaMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::probed(
                id: 'CCPA-1798.100',
                framework: ComplianceFramework::Ccpa,
                title: 'Right to Know',
                requirement: 'A consumer has the right to request that a business disclose the '
                    . 'categories and specific pieces of personal information it has collected '
                    . 'about them.',
                probe: new SubjectRightsProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CCPA-1798.105',
                framework: ComplianceFramework::Ccpa,
                title: 'Right to Delete',
                requirement: 'A consumer has the right to request that a business delete personal '
                    . 'information the business has collected from them, subject to the '
                    . 'statutory exceptions.',
                probe: new DataErasureProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CCPA-1798.120',
                framework: ComplianceFramework::Ccpa,
                title: 'Right to Opt-Out of Sale/Sharing',
                requirement: 'A consumer has the right, at any time, to direct a business that sells '
                    . 'or shares personal information about them to third parties not to do so.',
                probe: new ConsentManagementProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CCPA-1798.140',
                framework: ComplianceFramework::Ccpa,
                title: 'Data Categories and Sensitive PI',
                requirement: 'A business shall identify the categories of personal information it '
                    . 'collects, including the categories treated as sensitive personal '
                    . 'information.',
                artefact: 'The documented inventory of the personal-information categories '
                    . 'collected, the sensitive categories among them, and the business purpose '
                    . 'for each.',
            ),

            ControlDeclaration::probed(
                id: 'CCPA-1798.150',
                framework: ComplianceFramework::Ccpa,
                title: 'Data Security (Safe Harbor)',
                requirement: 'A business shall implement and maintain reasonable security procedures '
                    . 'and practices appropriate to the nature of the personal information it '
                    . 'holds.',
                probe: new DataProtectionAtRestProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CCPA-1798.185',
                framework: ComplianceFramework::Ccpa,
                title: 'CPRA Data Minimization',
                requirement: 'A business shall not retain personal information for longer than is '
                    . 'reasonably necessary for the disclosed purpose for which it was '
                    . 'collected.',
                probe: new DataRetentionProbe(),
            ),
        ];
    }
}
