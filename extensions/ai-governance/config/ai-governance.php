<?php

declare(strict_types=1);

/**
 * AI Governance Extension Configuration.
 *
 * @see https://www.iso.org/standard/81230.html ISO/IEC 42001:2023
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Audit Invocations
    |--------------------------------------------------------------------------
    |
    | When enabled, all AI model invocations are automatically logged to the
    | tamper-evident audit trail. Disable only in high-throughput scenarios
    | where invocation volume would overwhelm the audit store.
    |
    */
    'audit_invocations' => true,

    /*
    |--------------------------------------------------------------------------
    | Require Impact Assessment
    |--------------------------------------------------------------------------
    |
    | When enabled, models must have at least one impact assessment on record
    | before they can be deployed to production via deployment gates.
    |
    */
    'require_impact_assessment' => true,

    /*
    |--------------------------------------------------------------------------
    | Require Model Card
    |--------------------------------------------------------------------------
    |
    | When enabled, models must have a ModelCard attached documenting their
    | capabilities, limitations, and known biases before deployment.
    |
    */
    'require_model_card' => false,

    /*
    |--------------------------------------------------------------------------
    | Require Consent for Training Data
    |--------------------------------------------------------------------------
    |
    | When enabled, all training data provenance records must have consent
    | recorded before the associated model can be deployed.
    |
    */
    'require_consent_for_training_data' => true,

    /*
    |--------------------------------------------------------------------------
    | Risk-tier gates have no key here, deliberately
    |--------------------------------------------------------------------------
    |
    | Two deployment gates are always active and cannot be switched off from
    | this file. A model classified 'unacceptable' is refused production because
    | EU AI Act Article 5 prohibits the practice, and a model classified 'high'
    | must carry an impact assessment, a model card and at least one registered
    | monitoring hook because Articles 9, 11 and 72(3) require them before such
    | a system is placed on the market. Neither is an operator preference, so
    | neither gets a toggle. The require_* settings above are a different thing:
    | they are what an operator chooses to demand of EVERY model, whatever its
    | tier, and switching them off does not reach the two gates below them.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Store Implementations
    |--------------------------------------------------------------------------
    |
    | Configure which store implementations to use. Use 'memory' for in-memory
    | stores (development/testing) or provide a class-string for production stores.
    |
    */
    'registry_store' => 'memory',
    'data_governance_store' => 'memory',
    'explainability_store' => 'memory',
];
