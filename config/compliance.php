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
    |   ComplianceFramework::Gdpr — 4 probed controls, TWO of them observed since
    |       ADR-0050, and `composer compliance:check` fails on a default
    |       installation because of the other two. That is the gate working, and
    |       the failure is left standing rather than configured away:
    |
    |       Art 5(1)(f), Art 32  observed. libsodium is exercised and the key
    |           hierarchy actually derives; both are measurements.
    |       Art 25  CLAIMED AND NOT OBSERVED. It used to pass on
    |           PseudonymizationServiceInterface resolving to
    |           PseudonymizationService — which class would serve, never that a
    |           direct identifier was ever replaced. Nothing in this release puts
    |           a value through it.
    |       Art 33  CLAIMED AND NOT OBSERVED. Same shape: IncidentReporterInterface
    |           resolving to FileIncidentReporter, a register that would survive a
    |           restart with nothing ever written to it.
    |       Art 30  operator artefact, as it always was.
    |
    |       Two ways to close it, and one of them is not available. An observer
    |       may pseudonymise a synthetic identifier and read it back, exactly as
    |       TokenVaultObserver already exercises the token vault for PCI Req 3.4 —
    |       that is a real measurement and a real piece of work. Writing something
    |       that "exercises" the service by constructing it would be ADR-0041's
    |       defect wearing this file's approval, and is refused. Until one of them
    |       is built, a deployment subject to GDPR reads a report with two observed
    |       controls, two named gaps and one artefact, which is a more useful
    |       document than the four green lines it replaces and the first one that
    |       is true.
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
    |   Nis2       Art 21(i) needs classified routes, as above. Of the other four,
    |              three are observed and one is partial with its residual gap
    |              named — so the gate would pass, and the report would still
    |              show you what is open.
    |   Iso27001   A.8.3 needs classified routes, as above. Of the other five,
    |              three are observed and two are partial.
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
