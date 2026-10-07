<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Probe\MultiFactorAuthenticationProbe;
use Pulsar\Compliance\Probe\PanAtRestProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;

/**
 * Declares the PCI-DSS controls Pulsar can be assessed against.
 *
 * Every requirement text below is the standard's own words. The prose that used
 * to follow it — "Covered by TokenizationService …, and DatabaseTokenStore for
 * production persistence" — is gone, and its removal is the point of the whole
 * change: that sentence was the false claim ADR-0041 found, asserting which class
 * did the work in a deployment the mapping had never seen. Which class does the
 * work is now the probe's job to find out, per deployment, at report time.
 *
 * TWO CONTROLS MOVED TO THE OPERATOR CHECKLIST, and neither move is a retreat.
 * A control that names a subject the framework cannot see is worth less than no
 * control: it produces a colour, and the colour is about something else.
 *
 *  - Req 2.3's subject is the channel an administrator's traffic crosses. It was
 *    decided by the DATABASE session and the session cipher, with the HTTP
 *    transport present only as a Declared supporting fact that could not decide
 *    anything. TLS termination happens in the web server or at the edge, outside
 *    the PHP process, and a report generated from the CLI has no request to
 *    inspect. HSTS is not the missing measurement either: it is a header asking a
 *    browser to come back over TLS next time, emitted only on a request that was
 *    already secure.
 *  - Req 8.2 declared unique identification for all users and was decided by
 *    which two-factor manager resolved. Those are different requirements. What
 *    the MFA probe observes is Req 8.3, which is now the control it carries; the
 *    unique-identification obligation — no shared accounts — is an operational
 *    fact about how accounts are issued and is on the checklist.
 *
 * Identifiers follow PCI DSS v3.2.1 numbering, which is what this mapping has
 * always used. The v4.0.1 equivalents are named in the comment above each
 * declaration so an assessor working from the current standard can find them.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class PciDssMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            // v4.0.1: 1.2.4. The artefact that answers "anywhere it is stored" for
            // Req 3.4 below. Pulsar knows about one place account data can rest —
            // the vault it provides — and has no way to enumerate an application's
            // own tables, files, logs or caches. Nothing that is true of a
            // framework can close this; it is the assessor's question and it is
            // stated as one.
            ControlDeclaration::operatorResponsibility(
                id: 'Req1.1.3',
                framework: ComplianceFramework::PciDss,
                title: 'Cardholder Data Flow Diagram',
                requirement: 'Maintain a current diagram that shows all cardholder data flows '
                    . 'across systems and networks, identifying every location where account '
                    . 'data is stored, processed or transmitted.',
                artefact: 'The current cardholder data flow diagram, with the inventory of every '
                    . 'location this system stores account data and, for each one, the mechanism '
                    . 'that renders it unreadable. The compliance report evidences exactly one of '
                    . 'those locations — the Pulsar token vault, under Req 3.4 — and nothing '
                    . 'about the others.',
            ),

            // v4.0.1: 2.2.7. Probed until adversarial review pointed out that the
            // decisive facts were the database session and the session cipher,
            // neither of which is non-console administrative access. See the class
            // docblock; the short version is that the subject lives outside the PHP
            // process and outside the report's reach.
            ControlDeclaration::operatorResponsibility(
                id: 'Req2.3',
                framework: ComplianceFramework::PciDss,
                title: 'Encrypt Non-Console Administrative Access',
                requirement: 'Encrypt all non-console administrative access using strong cryptography.',
                artefact: 'The TLS configuration of every interface through which this system is '
                    . 'administered — the web server or edge that terminates HTTPS, the SSH '
                    . 'daemon, any management console — with a protocol and cipher scan of each '
                    . 'showing that plaintext administrative access is refused rather than merely '
                    . 'discouraged.',
            ),

            // v4.0.1: 3.5.1. The requirement text carries its own scope statement,
            // because the report prints it and that is where a reader looks. What
            // the probe decides is narrower than what the standard asks, the gap is
            // real, and naming it here is the difference between a narrowed control
            // and an overstated one.
            ControlDeclaration::probed(
                id: 'Req3.4',
                framework: ComplianceFramework::PciDss,
                title: 'Render PAN Unreadable Anywhere It Is Stored',
                requirement: 'Render PAN unreadable anywhere it is stored by using strong one-way '
                    . 'hash functions, truncation, index tokens, or strong cryptography. ASSESSED '
                    . 'NARROWLY: exactly one storage location is exercised — the token vault this '
                    . 'release ships — by putting a synthetic value through it and checking that '
                    . 'the persisted bytes conceal it. The places an application stores account '
                    . 'data cannot be enumerated from inside it, so a satisfied finding here means '
                    . 'the vault renders values unreadable, never that no PAN is stored readable '
                    . 'elsewhere. Req 1.1.3 carries that question and is an operator '
                    . 'responsibility.',
                probe: new PanAtRestProbe(),
                subject: ControlSubject::CardholderData,
            ),

            // v4.0.1: 6.2.4. A property of the BUILD, not of the deployment. Static
            // analysis, boundary enforcement and the test suite all ran on a
            // developer's machine or in CI; nothing the running application can be
            // asked will reveal whether they passed for the commit that is deployed.
            // The artefact an assessor should demand is named instead.
            ControlDeclaration::operatorResponsibility(
                id: 'Req6.5',
                framework: ComplianceFramework::PciDss,
                title: 'Address Common Coding Vulnerabilities',
                requirement: 'Address common coding vulnerabilities in software-development '
                    . 'processes, including injection flaws, buffer overflows, insecure '
                    . 'cryptographic storage, and cross-site scripting.',
                artefact: 'CI run showing the full quality gate green for the deployed commit, '
                    . 'with the commit SHA matching the one in service.',
            ),

            // v4.0.1: 8.2.1. Whether every user has an identifier of their own is a
            // fact about how accounts are issued and shared, not about which classes
            // are bound. Nothing in the running application distinguishes one
            // administrator's account from a credential four people know.
            ControlDeclaration::operatorResponsibility(
                id: 'Req8.1.1',
                framework: ComplianceFramework::PciDss,
                title: 'Unique Identification for All Users',
                requirement: 'Define and implement policies and procedures to ensure proper user '
                    . 'identification management, assigning all users a unique ID before allowing '
                    . 'them to access system components or cardholder data.',
                artefact: 'The account register for every person with access, showing one '
                    . 'identifier per person and no shared or generic credential in service, with '
                    . 'the joiners and leavers record behind it.',
            ),

            // v4.0.1: 8.4.1 / 8.4.2. This is the control the MFA probe actually
            // observes, and it now carries it. It replaces Req 8.2, which declared
            // unique identification and was decided by the two-factor manager.
            ControlDeclaration::probed(
                id: 'Req8.3',
                framework: ComplianceFramework::PciDss,
                title: 'Multi-Factor Authentication for Administrative and Remote Access',
                requirement: 'Secure all individual non-console administrative access and all '
                    . 'remote access to the cardholder data environment using multi-factor '
                    . 'authentication.',
                probe: new MultiFactorAuthenticationProbe(),
                subject: ControlSubject::Authentication,
            ),

            // v4.0.1: 10.2.1 / 10.3.2.
            ControlDeclaration::probed(
                id: 'Req10.2',
                framework: ComplianceFramework::PciDss,
                title: 'Automated Audit Trails',
                requirement: 'Implement automated audit trails for all system components to '
                    . 'reconstruct events, including user identification, event type, date and '
                    . 'time, success or failure indication, origination, and the identity or name '
                    . 'of affected data, system component, resource, or service.',
                probe: new TamperEvidentAuditProbe(),
                subject: ControlSubject::AuditTrail,
            ),
        ];
    }
}
