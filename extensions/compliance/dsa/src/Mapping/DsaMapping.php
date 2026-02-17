<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Mapping;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Maps DSA articles to Pulsar framework controls.
 *
 * Registers compliance controls that track which DSA obligations
 * are covered by framework features. The DSA applies in tiers:
 * intermediary services (basic), hosting services, online platforms,
 * and very large online platforms (VLOPs).
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class DsaMapping
{
    /**
     * Register DSA controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        // Art. 11-13: Transparency and contact points
        $catalog->register(new Control(
            id: 'dsa-art-11',
            framework: 'dsa',
            title: 'Points of contact for authorities',
            description: 'Intermediary services must designate a single point of contact for '
                . 'communication with member state authorities and the Commission. Covered '
                . 'by the DsaConfig contact_point configuration.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['dsa_config', 'contact_point'],
        ));

        $catalog->register(new Control(
            id: 'dsa-art-13',
            framework: 'dsa',
            title: 'Legal representative',
            description: 'Intermediary services not established in the EU must designate a legal '
                . 'representative in a member state. Covered by DsaConfig legal_representative field.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['dsa_config', 'legal_representative'],
        ));

        // Art. 14: Terms of service and content moderation policies
        $catalog->register(new Control(
            id: 'dsa-art-14',
            framework: 'dsa',
            title: 'Terms of service transparency',
            description: 'Providers must include clear information about content moderation policies '
                . 'in their terms of service, including restrictions applied, algorithmic '
                . 'decision-making, and the internal complaint mechanism. Covered by '
                . 'ModerationPolicy definitions.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['moderation_policy', 'content_moderation'],
        ));

        // Art. 15: Transparency reporting
        $catalog->register(new Control(
            id: 'dsa-art-15',
            framework: 'dsa',
            title: 'Transparency reporting obligations',
            description: 'All intermediary services must publish annual transparency reports on '
                . 'content moderation activities. Covered by TransparencyReportGenerator '
                . 'and ModerationLog.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['transparency_report', 'moderation_log'],
        ));

        // Art. 16: Notice-and-action mechanisms (hosting services)
        $catalog->register(new Control(
            id: 'dsa-art-16',
            framework: 'dsa',
            title: 'Notice-and-action mechanism',
            description: 'Hosting services must allow individuals to notify them of illegal content. '
                . 'Notices must be processed promptly and diligently. Covered by '
                . 'NoticeAndActionHandler and IllegalContentNotice.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['notice_action', 'illegal_content_notice'],
        ));

        // Art. 17: Statement of reasons
        $catalog->register(new Control(
            id: 'dsa-art-17',
            framework: 'dsa',
            title: 'Statement of reasons for restrictions',
            description: 'Hosting services must provide a clear statement of reasons to affected '
                . 'users when restricting content, including the facts, legal basis, and '
                . 'available redress. Covered by ModerationDecision reason field and appeal_url.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['moderation_decision', 'statement_of_reasons'],
        ));

        // Art. 20: Internal complaint-handling
        $catalog->register(new Control(
            id: 'dsa-art-20',
            framework: 'dsa',
            title: 'Internal complaint-handling system',
            description: 'Online platforms must provide an internal complaint system allowing users '
                . 'to contest moderation decisions. Complaints must be handled by qualified '
                . 'staff in a timely manner. Covered by AppealHandler.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['appeal_handler', 'complaint_handling'],
        ));

        // Art. 22: Trusted flaggers
        $catalog->register(new Control(
            id: 'dsa-art-22',
            framework: 'dsa',
            title: 'Trusted flaggers',
            description: 'Online platforms must process notices from trusted flaggers with priority. '
                . 'Covered by TrustedFlaggerRegistry and priority processing in '
                . 'NoticeAndActionHandler.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['trusted_flagger_registry', 'priority_processing'],
        ));

        // Art. 34-35: VLOP systemic risk assessment
        $catalog->register(new Control(
            id: 'dsa-art-34',
            framework: 'dsa',
            title: 'Systemic risk assessment (VLOPs)',
            description: 'Very large online platforms must identify, analyze, and assess systemic '
                . 'risks arising from the functioning of their services. Framework provides '
                . 'the data collection and reporting infrastructure; risk assessment '
                . 'methodology is the deployer responsibility.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['moderation_log', 'transparency_report'],
        ));

        // Art. 40: Data access for researchers
        $catalog->register(new Control(
            id: 'dsa-art-40',
            framework: 'dsa',
            title: 'Data access for vetted researchers (VLOPs)',
            description: 'VLOPs must provide access to data for vetted researchers to conduct '
                . 'research on systemic risks. Framework provides data export capabilities '
                . 'through the ModerationLog query interface.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['moderation_log', 'data_export'],
        ));
    }
}
