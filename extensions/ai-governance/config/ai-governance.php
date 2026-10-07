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
    | Where the AI management system record is kept. Three values are accepted
    | per key:
    |
    |   'database' — the shipped durable store, over the deployment's own
    |                connection. This is the DEFAULT, and it changed in rc.12.
    |                Every key below used to default to 'memory', so a deployment
    |                that enabled this extension and changed nothing kept its
    |                model inventory, impact assessments, data provenance,
    |                explanations and monitoring results in the memory of one
    |                worker. ISO 42001:2023 Clause 7.5 asks for documented
    |                information and Clause 9.1 asks for retained evidence of
    |                monitoring results; a record that is gone at the next
    |                restart discharges neither, so the setting that retains
    |                nothing is no longer the one you get by not choosing.
    |
    |   'memory'   — the development store. Usable, and evidence of nothing after
    |                a restart. Choosing it is a statement about this deployment
    |                that the compliance report will repeat back.
    |
    |   a class    — a service id the container can resolve to an implementation
    |                of the corresponding contract, for a deployment whose
    |                governance record lives in a system this framework does not
    |                own.
    |
    | 'database' requires a database connection. It does NOT silently fall back
    | to memory when there is none: the boot fails naming the key, because a
    | silent fallback is exactly how a deployment comes to believe it is
    | retaining a record it is not. Run `pulsar migrate` to create the tables.
    |
    */
    'registry_store' => 'database',
    'impact_assessment_store' => 'database',
    'data_governance_store' => 'database',
    'explainability_store' => 'database',
    'monitoring_record_store' => 'database',

    /*
    |--------------------------------------------------------------------------
    | Transparency Store
    |--------------------------------------------------------------------------
    |
    | Where declared EU AI Act Article 50 positions are kept. It takes the same
    | three values as the keys above, and it is listed separately because the
    | duty behind it is the one that BINDS TODAY: Article 50 has applied since
    | 2 August 2026, and the digital omnibus that deferred the high-risk chapter
    | to 2027 and 2028 left it untouched.
    |
    | This key does not decide whether the transparency contract is bound. It
    | always is — a deployment does not discharge a live obligation by declining
    | to wire the thing that expresses it. It decides only where a declaration
    | is kept, and until rc.12 that was decided here for everyone: in memory,
    | with no alternative, for the only obligation in this extension that was
    | already in force.
    |
    | Choose 'memory' when every surface is declared in code, on every boot. The
    | declaration is then rebuilt from the source that defines it, and a database
    | row would be a second copy that can fall out of step with the code it
    | describes. Choose 'database' — the default — when a surface can be declared
    | at runtime, which the contract permits: an operator-facing governance
    | console declaring a surface into 'memory' loses it at the next restart,
    | silently, and the compliance report then lists fewer surfaces than the
    | deployment actually declared.
    |
    */
    'transparency_store' => 'database',

    /*
    |--------------------------------------------------------------------------
    | Human Oversight Store
    |--------------------------------------------------------------------------
    |
    | Where the EU AI Act Article 14 and Article 26(2) oversight record is kept:
    | who is assigned to oversee each system, on what stated competence and
    | authority, and every occasion on which one of them disregarded, reversed or
    | halted the machine.
    |
    | Same three values as the keys above. 'memory' is a development choice and a
    | poor one here even by the standard of the other stores: the intervention
    | register is the only artefact in this subsystem that is EVIDENCE rather than
    | intent, and it is what a person contesting a reversed decision under GDPR
    | Article 22(3) is shown weeks after the fact.
    |
    */
    'oversight_store' => 'database',

    /*
    |--------------------------------------------------------------------------
    | Actor Role
    |--------------------------------------------------------------------------
    |
    | What THIS DEPLOYMENT is, under the EU AI Act, with respect to the AI systems
    | it runs. This is the question the Act answers before any other, because it
    | decides which obligations apply:
    |
    |   'provider'              Article 3(3). Develops a system, or has one
    |                           developed, and places it on the market or puts it
    |                           into service under its own name. Bears the
    |                           Article 16 obligations: the risk management system
    |                           (Article 9), the Annex IV technical documentation
    |                           (Article 11), the post-market monitoring plan
    |                           (Article 72(3)), and the rest.
    |
    |   'deployer'              Article 3(4). Uses a system under its own
    |                           authority, professionally. Bears Article 26
    |                           instead: use per the instructions for use, human
    |                           oversight assigned to competent and authorised
    |                           natural persons (26(2)), input data governance,
    |                           monitoring, and six months of retained logs (26(6)).
    |
    |   'provider_and_deployer' Both. The usual answer for an organisation that
    |                           builds and runs its own system, and the position
    |                           Article 25 moves a deployer into once it puts its
    |                           name on a bought-in high-risk system, substantially
    |                           modifies one, or repurposes a system into the
    |                           high-risk tier.
    |
    | THERE IS NO DEFAULT, and the key ships absent. A deployment that has not
    | declared a role has not answered the question, and a high-risk model is then
    | refused deployment naming both obligation sets rather than being assumed
    | into one — assuming 'provider' would tell a deployer its conformity rests on
    | documentation it will never hold, and assuming 'deployer' would waive the
    | pre-market duties of an actual provider. A value this file does not list
    | fails the boot rather than reading as "unstated": a misspelt role must not
    | become an exemption.
    |
    | A single system overrides this through AiModel::$actorRole, which is what an
    | organisation that provides one model and deploys another needs.
    |
    */
    // 'actor_role' => 'deployer',
];
