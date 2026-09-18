<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Mapping;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;

/**
 * Declares the Digital Services Act controls Pulsar can be assessed against.
 *
 * Contact points, terms of service, transparency reports, notice-and-action and
 * researcher access are obligations on the service provider, discharged in
 * published documents and in the product, not in framework configuration. The one
 * runtime-observable obligation is that a statement of reasons, once issued, is
 * retained provably.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class DsaMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::operatorResponsibility(
                id: 'dsa-art-11',
                framework: ComplianceFramework::Dsa,
                title: 'Points of contact for authorities',
                requirement: 'Providers of intermediary services shall designate a single point of '
                    . 'contact to enable direct communication with member state authorities, '
                    . 'the Commission and the Board (Article 11).',
                artefact: 'The published point of contact for member state authorities and the '
                    . 'Commission, with the record of its notification.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'dsa-art-13',
                framework: ComplianceFramework::Dsa,
                title: 'Legal representative',
                requirement: 'Providers not established in the Union that offer services in the Union '
                    . 'shall designate a legal representative in one of the member states where '
                    . 'they offer services (Article 13).',
                artefact: 'The designation of the legal representative in a member state, with the '
                    . 'notification to that state\'s Digital Services Coordinator.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'dsa-art-14',
                framework: ComplianceFramework::Dsa,
                title: 'Terms of service transparency',
                requirement: 'Providers shall include in their terms and conditions information on any '
                    . 'restrictions they impose on the use of their service, in clear and '
                    . 'unambiguous language (Article 14).',
                artefact: 'The terms and conditions in force, showing the restrictions applied, the '
                    . 'algorithmic decision-making disclosed and the complaint mechanism '
                    . 'described.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'dsa-art-15',
                framework: ComplianceFramework::Dsa,
                title: 'Transparency reporting obligations',
                requirement: 'Providers of intermediary services shall publish, at least once a year, '
                    . 'clear and easily comprehensible reports on any content moderation they '
                    . 'engaged in (Article 15).',
                artefact: 'The published transparency report for the reporting period, with the '
                    . 'figures the Article requires.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'dsa-art-16',
                framework: ComplianceFramework::Dsa,
                title: 'Notice-and-action mechanism',
                requirement: 'Providers of hosting services shall put mechanisms in place to allow any '
                    . 'individual or entity to notify them of information they consider to be '
                    . 'illegal content (Article 16).',
                artefact: 'The notice-and-action mechanism as offered to users, with the log of '
                    . 'notices received and the decisions taken on them.',
            ),

            ControlDeclaration::probed(
                id: 'dsa-art-17',
                framework: ComplianceFramework::Dsa,
                title: 'Statement of reasons for restrictions',
                requirement: 'Providers of hosting services shall provide a clear and specific '
                    . 'statement of reasons to any affected recipient for any restriction '
                    . 'imposed, and retain it (Article 17).',
                probe: new TamperEvidentAuditProbe(),
                subject: ControlSubject::AuditTrail,
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'dsa-art-20',
                framework: ComplianceFramework::Dsa,
                title: 'Internal complaint-handling system',
                requirement: 'Providers of online platforms shall provide recipients with access to an '
                    . 'effective internal complaint-handling system for at least six months '
                    . 'following a decision (Article 20).',
                artefact: 'The internal complaint-handling system as offered to users, with the '
                    . 'record of complaints and their outcomes.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'dsa-art-22',
                framework: ComplianceFramework::Dsa,
                title: 'Trusted flaggers',
                requirement: 'Providers of online platforms shall ensure that notices submitted by '
                    . 'trusted flaggers are given priority and processed without undue delay '
                    . '(Article 22).',
                artefact: 'The register of trusted flaggers recognised, with evidence that their '
                    . 'notices are given priority.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'dsa-art-34',
                framework: ComplianceFramework::Dsa,
                title: 'Systemic risk assessment (VLOPs)',
                requirement: 'Providers of very large online platforms shall diligently identify, '
                    . 'analyse and assess any systemic risks stemming from the design or '
                    . 'functioning of their service (Article 34).',
                artefact: 'The systemic risk assessment for the service, covering the risk '
                    . 'categories the Article names, with the mitigation measures it produced.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'dsa-art-40',
                framework: ComplianceFramework::Dsa,
                title: 'Data access for vetted researchers (VLOPs)',
                requirement: 'Providers of very large online platforms shall provide vetted '
                    . 'researchers with access to data necessary to study systemic risks '
                    . '(Article 40).',
                artefact: 'The data access procedure offered to vetted researchers, with the record '
                    . 'of requests received and access granted.',
            ),
        ];
    }
}
