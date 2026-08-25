<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\MultiFactorAuthenticationProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;

/**
 * Declares the PSD2 and RTS controls Pulsar can be assessed against.
 *
 * Dynamic linking and the SCA exemptions turn on how a specific payment flow was
 * built and on fraud rates the framework never sees, so they name the design and
 * reporting evidence instead of being probed.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class Psd2Mapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::probed(
                id: 'Art97.1',
                framework: ComplianceFramework::Psd2,
                title: 'Strong Customer Authentication',
                requirement: 'Payment service providers shall apply strong customer authentication '
                    . 'where the payer accesses a payment account online, initiates an '
                    . 'electronic payment transaction, or carries out any action through a '
                    . 'remote channel implying a risk of fraud.',
                probe: new MultiFactorAuthenticationProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'Art97.2',
                framework: ComplianceFramework::Psd2,
                title: 'SCA Dynamic Linking',
                requirement: 'For remote electronic payment transactions, the authentication code '
                    . 'shall be dynamically linked to the amount and the payee of the '
                    . 'transaction.',
                artefact: 'The design record of the payment authentication flow showing how the '
                    . 'authentication code is dynamically linked to the amount and the payee, '
                    . 'with the test evidence for that linkage.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'RTS.Art16',
                framework: ComplianceFramework::Psd2,
                title: 'Low-Value Transaction Exemption',
                requirement: 'Payment service providers may be exempted from strong customer '
                    . 'authentication for remote electronic payments below the thresholds and '
                    . 'cumulative limits set by the regulatory technical standards (Article '
                    . '16).',
                artefact: 'The documented low-value exemption policy and evidence of the cumulative '
                    . 'amount and consecutive-transaction counters that enforce its limits.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'RTS.Art18',
                framework: ComplianceFramework::Psd2,
                title: 'Transaction Risk Analysis',
                requirement: 'Payment service providers may be exempted from strong customer '
                    . 'authentication where a real-time transaction risk analysis identifies no '
                    . 'abnormal pattern and the fraud rate remains below the reference '
                    . 'threshold (Article 18).',
                artefact: 'The transaction risk analysis methodology and the fraud rate reporting '
                    . 'submitted for the exemption threshold claimed.',
            ),

            // Article 66 obliges an ASPSP to ALLOW account information service
            // providers in, without discrimination, on the user's explicit consent.
            // It was decided by whether classified routes carry their required
            // middleware — a measure of keeping requests out, which is the
            // opposite obligation, and which no amount of tightening can evidence.
            ControlDeclaration::operatorResponsibility(
                id: 'Art66',
                framework: ComplianceFramework::Psd2,
                title: 'Account Information Service Provider Access',
                requirement: 'Payment service providers shall allow account information service '
                    . 'providers to access payment account information with the explicit '
                    . 'consent of the payment service user, and shall not discriminate against '
                    . 'such requests.',
                artefact: 'The dedicated interface offered to account information service '
                    . 'providers, its published availability and performance statistics, and '
                    . 'the record of the consent captured for each access.',
            ),

            // Article 67 is Article 66's obligation for payment initiation service
            // providers, and was probed the same way, with the same inversion.
            ControlDeclaration::operatorResponsibility(
                id: 'Art67',
                framework: ComplianceFramework::Psd2,
                title: 'Payment Initiation Service Provider Access',
                requirement: 'Account servicing payment service providers shall allow payment '
                    . 'initiation service providers to initiate payments with the explicit '
                    . 'consent of the payer, treating such requests without discrimination.',
                artefact: 'The payment initiation interface offered to third-party providers, '
                    . 'the record of the payer\'s explicit consent for each initiation, and the '
                    . 'evidence that such requests are not treated differently from the '
                    . 'institution\'s own.',
            ),

            ControlDeclaration::probed(
                id: 'Art94',
                framework: ComplianceFramework::Psd2,
                title: 'Audit Trail for Payment Transactions',
                requirement: 'Payment service providers shall keep records of payment transactions '
                    . 'sufficient to evidence authentication, execution and any fraud, and '
                    . 'retain them for the period national law prescribes.',
                probe: new TamperEvidentAuditProbe(),
            ),
        ];
    }
}
