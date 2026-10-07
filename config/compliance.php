<?php

declare(strict_types=1);

use Pulsar\Compliance\ComplianceFramework;

/**
 * Compliance profile configuration.
 *
 * Enable the regulatory frameworks your application must comply with.
 * The framework automatically resolves the most restrictive settings
 * across all enabled frameworks and applies them to session management,
 * password policies, audit retention, encryption, and breach notification.
 *
 * @see \Pulsar\Compliance\ComplianceProfileResolver
 * @see \Pulsar\Compliance\ComplianceProfile
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Enabled Compliance Frameworks
    |--------------------------------------------------------------------------
    |
    | ENABLING A FRAMEWORK HERE IS A CLAIM, and `pulsar compliance:report` holds
    | you to it: every control the framework's mapping declares is assessed
    | against THIS deployment, and a control the deployment does not show fails
    | the report and, through `composer compliance:check`, the build. There is no
    | second key that silences a standard — the way to stop being assessed
    | against one is to stop enabling it, in a diff someone can read.
    |
    | The list below is therefore not "the frameworks Pulsar supports". It is the
    | frameworks this deployment is subject to and is prepared to be held to.
    |
    |   ComplianceFramework::Gdpr — 4 probed controls, ALL FOUR observed on a
    |       default installation that has a master key, so
    |       `composer compliance:check` passes. It did not always, and the way each
    |       of them was closed is the only thing that makes the pass worth
    |       anything: every one rests on a subsystem being put through its work,
    |       and none of them on a class being bound. Unset PULSAR_MASTER_KEY and
    |       three of the four go red — Art 25, Art 32 and Art 5(1)(f), because
    |       SecurityWiring builds the pseudonymisation service and the encryptor
    |       inside the block the key opens. Art 33 stays green, correctly: the
    |       incident register is bound whether or not there is a key, and it still
    |       records a breach and gives it back:
    |
    |       Art 25  observed since ADR-0065. PseudonymizationObserver puts a
    |           synthetic identifier through the live service — replaced,
    |           recorded, resolved back byte for byte, then ERASED through the
    |           Article 17 service, which is both the control and the reason the
    |           check can write to a re-identification table at all. It used to
    |           rest on PseudonymizationServiceInterface resolving to
    |           PseudonymizationService: which class would serve, never that an
    |           identifier was replaced.
    |       Art 33  observed since ADR-0065. IncidentRegisterObserver records an
    |           incident through the live register and reads it back by id with
    |           its severity, title, metadata and timestamp intact — the clock the
    |           72-hour deadline runs from. It used to rest on
    |           IncidentReporterInterface resolving to FileIncidentReporter: a
    |           register that would survive a restart, with nothing ever written
    |           to it. NOTE that this one leaves a row: an incident register has
    |           no removal, deliberately, so each report run appends one
    |           Low-severity record under source
    |           `compliance.incident_register_probe` that says in its own title
    |           that it is not a security event.
    |       Art 5(1)(f), Art 32  observed since ADR-0066, and they are the two
    |           that went red first before they went green. ADR-0061 regraded
    |           `extension_loaded('sodium')` from Measured to Available and
    |           ADR-0062 then required a fact to be about the control's own estate,
    |           which left both articles — declared over PERSONAL DATA — resting
    |           on nothing, since the key derivation is about the key hierarchy and
    |           the session seal is about session payloads. PersonalDataSealObserver
    |           closed them by measuring that estate: it hands the deployment a
    |           field classified as personal data and reads back what would be
    |           stored, requiring the stored form to conceal the value, to open to
    |           it byte for byte, to REFUSE a copy with one byte changed, and not
    |           to seal two equal values alike. What varies between deployments is
    |           which EncryptorInterface answered, and that is the contract an
    |           application overrides — so bind an unauthenticated cipher and both
    |           articles fail while every binding inspection still reads clean.
    |           Nothing is written: the at-rest form is returned, not stored.
    |       Art 30  operator artefact, as it always was.
    |
    |       WHAT NONE OF THE FOUR ESTABLISHES, said here because a passing gate is
    |       exactly where it stops being read: Pulsar knows its own subsystems
    |       work. It does not know whether your application puts its identifiers
    |       through the pseudonymisation service, classifies its personal data, or
    |       reports its breaches to the register — and nothing in this tree could
    |       find out. A green report is evidence about the framework under your
    |       application, and the controller's obligations are yours.
    |
    | An empty list is not the way out: `compliance:report` refuses to produce a
    | report when nothing is enabled, because "nothing to assess" printed as green
    | is how a misconfigured pipeline reports compliance forever.
    |
    | Add the frameworks YOUR deployment is subject to. Expect the report to fail
    | at first: that is the point, and the failures name the missing fact and what
    | would close it. What follows is what a default installation of this release
    | does NOT deliver, per framework, so the first run holds no surprises.
    |
    |   PciDss     Req 3.4 tokenizes through DatabaseTokenStore, and this release
    |              ships no migration creating the `token_vault` table it writes
    |              to, so the vault throws on first use. Create the table, then
    |              enable it — the report exercises the vault for real and will
    |              say whether it renders a stored value unreadable.
    |   Hipaa      §164.308(a)(7) needs a backup and restore primitive; there is
    |              no such contract in this tree at all. §164.312(a)(1) and the
    |              2026 asset-inventory control need routes tagged with a data
    |              classification, which only your application can do.
    |   Soc2       Twelve of its eighteen probed controls rest on something this
    |              release does not ship: the backup primitive above, a consent
    |              manager that persists, a trace exporter that leaves the box.
    |              Twenty-four more are operator artefacts. SOC 2 is an
    |              attestation about an organisation; the software is a part of it.
    |   Nis2       Art 21(i) needs classified routes, as above. Art 23, incident
    |              reporting, is observed since ADR-0065 — it cites the same
    |              register measurement GDPR Art 33 does, because one deployment
    |              cannot have two answers to "can a breach be recorded and
    |              produced again". The remaining three rest on facts this release
    |              does not measure; enable it and read the report rather than
    |              trusting a count written here, which is exactly the drift this
    |              subsystem exists to remove.
    |   Iso27001   A.8.3 needs classified routes, as above. A.8.24 is observed on
    |              the key derivation running against the key in service. For the
    |              rest, enable it and read the report.
    |   Iso42001   Every control depends on the `pulsar/ai-governance` extension,
    |              which is a bundled product extension and off by default. A
    |              deployment that operates no AI system has no subject for any of
    |              them, and enabling the framework to see fourteen failures says
    |              nothing about the deployment.
    |   AiAct      Twenty of its twenty-two controls name an operator artefact
    |              rather than a probe, because almost every AI Act duty is
    |              discharged in conduct or in documents no framework observes.
    |              Read the dates before enabling it: the digital omnibus in
    |              force since 27 July 2026 deferred the whole high-risk chapter
    |              to 2 December 2027 (Annex III) and 2 August 2028 (Annex I),
    |              and left Article 50 transparency at 2 August 2026 — so the
    |              chapter most deployments prepared for does not bind yet, and
    |              the one that does is Article 50. Its two probed controls both
    |              depend on `pulsar/ai-governance`, off by default like ISO
    |              42001 above. A deployment that operates no AI system has no
    |              subject for any of this and should not enable it.
    |   Ccpa, Dora, Eidas, Hl7Fhir, Iso13485, Mdr, NistCsf, Psd2, SwiftCsp,
    |   Dsa, DataAct — all mapped and all assessable; run
    |   `pulsar compliance:report` after enabling one to see where it stands.
    |
    | The strictest intersection of everything enabled here is also what
    | ComplianceProfileResolver hands to session management, password policy,
    | audit retention, encryption and breach-notification deadlines. Enabling a
    | framework tightens the running application, not only the report.
    |
    */
    'enabled_frameworks' => [
        ComplianceFramework::Gdpr,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Engine
    |--------------------------------------------------------------------------
    |
    | The compliance verification engine continuously proves that security
    | controls are active and configured correctly. It detects configuration
    | regressions, cross-framework conflicts, and produces evidence for audits.
    |
    | boot_check runs the checks at boot and reports failures (or refuses the
    | boot under strict_mode). It deliberately records NO evidence: the kernel
    | boots once per request under PHP-FPM, and one signed evidence record per
    | request is not an evidence trail.
    |
    | evidence_interval is the minimum number of seconds between two evidence
    | records, and it drives the `compliance:collect-evidence` scheduled job. The
    | trail is therefore only collected where `pulsar scheduler:tick` runs and
    | config/scheduler.php enables the scheduler; `pulsar scheduler:list` says
    | whether it is registered. Records are HMAC-chained under a key derived from
    | PULSAR_MASTER_KEY — without that key there is no chain, and the boot log
    | says so rather than storing forgeable records.
    |
    */
    'verification' => [
        'enabled' => true,
        'boot_check' => true,
        'evidence_interval' => 3600,
        'strict_mode' => false,
    ],
];
