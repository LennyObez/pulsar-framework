<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers ISO/IEC 42001:2023 AI Management System controls into the catalog.
 *
 * Maps Pulsar framework features (core + AI governance extension) to ISO 42001
 * requirements. This is the world's first PHP framework to implement these controls.
 *
 * ISO 42001 clauses:
 * - 4: Context of the organization
 * - 5: Leadership
 * - 6: Planning (risk assessment, objectives)
 * - 7: Support (resources, competence, documentation)
 * - 8: Operation (AI system lifecycle, data management)
 * - 9: Performance evaluation (monitoring, audit, review)
 * - 10: Improvement (nonconformity, continual improvement)
 * - Annex A: Reference controls (A.2–A.10)
 *
 * @see https://www.iso.org/standard/81230.html
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class Iso42001Mapping
{
    /**
     * Register ISO 42001:2023 controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        // --- Clause 6: Planning ---

        $catalog->register(new Control(
            id: 'ISO42001-6.1.2',
            framework: 'iso42001',
            title: 'AI Risk Assessment',
            description: 'The organization shall establish a process for AI risk assessment that identifies '
                . 'risks to individuals, groups, and societies. Covered by the AiImpactAssessmentInterface '
                . 'with structured findings across fairness, transparency, accountability, privacy, safety, '
                . 'and security categories. Risk scoring from 0.0 to 10.0 with severity-weighted computation.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['ai_impact_assessment', 'ai_risk_scoring', 'impact_categories'],
        ));

        $catalog->register(new Control(
            id: 'ISO42001-6.1.4',
            framework: 'iso42001',
            title: 'AI Risk Treatment',
            description: 'The organization shall define and apply an AI risk treatment process. Covered by '
                . 'deployment gates that enforce pre-production validation checks, model risk level '
                . 'classification (minimal/limited/high/unacceptable per EU AI Act alignment), and '
                . 'lifecycle transition controls that prevent unsafe deployments.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['deployment_gates', 'ai_risk_levels', 'lifecycle_transitions'],
        ));

        // --- Clause 7: Support ---

        $catalog->register(new Control(
            id: 'ISO42001-7.5',
            framework: 'iso42001',
            title: 'Documented Information',
            description: 'The organization shall control documented information required by the AIMS. '
                . 'Covered by model cards (ModelCard DTO) that document model capabilities, limitations, '
                . 'known biases, training data sources, performance metrics, and ethical considerations. '
                . 'Structured as immutable readonly DTOs with mandatory fields.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['model_cards', 'ai_model_registry', 'data_provenance'],
        ));

        // --- Clause 8: Operation ---

        $catalog->register(new Control(
            id: 'ISO42001-8.2',
            framework: 'iso42001',
            title: 'AI System Impact Assessment',
            description: 'The organization shall conduct an AI system impact assessment. Covered by '
                . 'AiImpactAssessmentInterface with structured assessment across six categories, '
                . 'severity classification (low/medium/high/critical), and actionable recommendations.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['ai_impact_assessment', 'impact_findings', 'impact_recommendations'],
        ));

        $catalog->register(new Control(
            id: 'ISO42001-8.3',
            framework: 'iso42001',
            title: 'Data for AI Systems',
            description: 'The organization shall manage data used for AI systems including quality, '
                . 'provenance, and consent. Covered by AiDataGovernanceInterface with training data '
                . 'provenance tracking (source, license, transformations), data quality reports '
                . '(completeness, accuracy, consistency metrics), and consent verification.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['data_provenance', 'data_quality_reports', 'consent_tracking'],
        ));

        $catalog->register(new Control(
            id: 'ISO42001-8.4',
            framework: 'iso42001',
            title: 'AI System Life Cycle',
            description: 'The organization shall manage AI systems throughout their life cycle. Covered by '
                . 'AiLifecycleManagerInterface with six lifecycle stages (development → testing → staging '
                . '→ production → deprecated → retired), deployment gates, monitoring hooks, and rollback.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['ai_lifecycle_manager', 'deployment_gates', 'monitoring_hooks', 'model_rollback'],
        ));

        // --- Clause 9: Performance Evaluation ---

        $catalog->register(new Control(
            id: 'ISO42001-9.1',
            framework: 'iso42001',
            title: 'Monitoring, Measurement, Analysis, and Evaluation',
            description: 'The organization shall monitor and measure AI system performance. Covered by '
                . 'MonitoringHookInterface for registering production monitoring checks, AiAuditLoggerInterface '
                . 'for tamper-evident logging of all AI events (invocations, decisions, overrides, bias '
                . 'detection), and integration with core observability (OpenTelemetry, metrics).',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['monitoring_hooks', 'ai_audit_logging', 'observability'],
        ));

        $catalog->register(new Control(
            id: 'ISO42001-9.2',
            framework: 'iso42001',
            title: 'Internal Audit',
            description: 'The organization shall conduct internal audits. Covered by the HMAC-chained audit '
                . 'trail with AI-specific event types (ModelInvoked, DecisionMade, HumanOverride, '
                . 'BiasDetected, ModelDeployed, ModelRetired), tamper detection, and compliance reporting.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['ai_audit_logging', 'hmac_chain', 'compliance_reporting'],
        ));

        // --- Clause 10: Improvement ---

        $catalog->register(new Control(
            id: 'ISO42001-10.1',
            framework: 'iso42001',
            title: 'Continual Improvement',
            description: 'The organization shall continually improve the AIMS. Covered by model versioning '
                . 'in the registry (version tracking, status transitions), monitoring hooks that detect '
                . 'drift and degradation, and the ability to retire and replace models.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['model_versioning', 'monitoring_hooks', 'model_retirement'],
        ));

        // --- Annex A Controls ---

        $catalog->register(new Control(
            id: 'ISO42001-A.2',
            framework: 'iso42001',
            title: 'AI Policy',
            description: 'The organization shall establish an AI policy. Framework provides configurable '
                . 'governance settings (audit_invocations, require_impact_assessment, require_model_card, '
                . 'require_consent_for_training_data) as policy controls. Organizational AI policy documents '
                . 'are the deployer\'s responsibility.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['ai_governance_config', 'deployment_gates'],
        ));

        $catalog->register(new Control(
            id: 'ISO42001-A.5',
            framework: 'iso42001',
            title: 'Data for AI Systems',
            description: 'Controls for managing data used in AI systems. Covered by DataProvenance DTO '
                . '(source, license, consent, transformations), DataQualityReport DTO (completeness, '
                . 'accuracy, consistency), and consent verification across all provenance records.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['data_provenance', 'data_quality_reports', 'consent_tracking'],
        ));

        $catalog->register(new Control(
            id: 'ISO42001-A.6',
            framework: 'iso42001',
            title: 'AI System Life Cycle',
            description: 'Controls for AI system development, deployment, and retirement. Covered by '
                . 'model registry with lifecycle states, deployment gate validation, and rollback.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['ai_lifecycle_manager', 'ai_model_registry', 'deployment_gates'],
        ));

        $catalog->register(new Control(
            id: 'ISO42001-A.7',
            framework: 'iso42001',
            title: 'AI System Operation and Monitoring',
            description: 'Controls for operating and monitoring AI systems. Covered by monitoring hooks '
                . 'that run health checks on production models and AI audit logging that tracks '
                . 'all model invocations, decisions, and human overrides.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['monitoring_hooks', 'ai_audit_logging', 'ai_invocation_logging'],
        ));

        $catalog->register(new Control(
            id: 'ISO42001-A.8',
            framework: 'iso42001',
            title: 'Transparency and Explainability',
            description: 'Controls ensuring transparency in AI decision-making. Covered by '
                . 'ExplainabilityInterface with structured explanations (decision factors, confidence '
                . 'scores, alternatives considered), human override logging, and model card documentation.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['explainability', 'decision_factors', 'human_override_logging', 'model_cards'],
        ));

        $catalog->register(new Control(
            id: 'ISO42001-A.10',
            framework: 'iso42001',
            title: 'AI System Documentation',
            description: 'Controls for documenting AI systems and their impacts. Covered by model cards, '
                . 'impact assessment findings, data provenance records, and comprehensive audit logs.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['model_cards', 'ai_impact_assessment', 'data_provenance', 'ai_audit_logging'],
        ));
    }
}
