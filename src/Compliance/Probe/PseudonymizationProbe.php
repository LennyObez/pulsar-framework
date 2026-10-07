<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ControlProbeInterface;
use Pulsar\Compliance\Control\ControlRequirement;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\RequiredFact;

/**
 * Whether a direct identifier is actually replaced, resolved back and erased.
 *
 * WHAT THIS PROBE USED TO ASK, and why that was not enough. It required
 * {@see ObservationId::PseudonymizationResolved} alone — which concrete class
 * answered `PseudonymizationServiceInterface` — and GDPR Art 25 therefore failed
 * on every default installation as "claimed and not observed", because a
 * resolution says which class would serve a request and never that an identifier
 * was replaced. ADR-0046 left that failure standing on purpose and named the only
 * honest way to close it: put a value through the service, as
 * {@see PanAtRestProbe} does for the token vault.
 *
 * SO THE DECIDING FACT IS {@see ObservationId::IdentifierPseudonymizedAndErased}:
 * a synthetic identifier pseudonymised, recorded, resolved back byte for byte,
 * and then erased through the service the deployment would actually use for an
 * Article 17 request. See {@see \Pulsar\Compliance\Evidence\PseudonymizationObserver}
 * for what it writes and how the erasure step is the control and the cleanup at
 * once.
 *
 * AND {@see ObservationId::PseudonymTablePersistence} BESIDE IT, because the
 * measurement alone would have re-opened the defect it closes. That measurement
 * runs in one process, and every one of its subjects passes over
 * `InMemoryPseudonymLookup` — the development stub — which has forgotten every
 * mapping by the next request. A deployment standing on it can produce a
 * pseudonym and can never resolve one again, so it can neither answer an Art 15
 * request about the pseudonymised record nor perform the Art 17 erasure, because
 * there is nothing left to erase. No in-process check can see that; only the
 * identity of the table can.
 *
 * Declared as its own class rather than through {@see CapabilityProbe} for the
 * reason {@see PanAtRestProbe} is: its failure modes are not interchangeable and
 * must not print the same way. A service that mints but cannot erase, and a table
 * that forgets on restart, are not degrees of the same problem — and grading
 * either one Partial because the other was observed would tell an operator to fix
 * the wrong thing and let `composer compliance:check` pass over it, since the
 * gate fails on Unsatisfied and not on Partial. Both deciding facts are therefore
 * {@see RequiredFact::essential()}, and the service's identity stays
 * {@see RequiredFact::contributing()}: it names the class an assessor will ask
 * about, and it cannot decide a control the measurement beside it already
 * exercised.
 *
 * WHAT THIS PROBE DOES NOT AND CANNOT SEE, stated because Art 25's own words are
 * "by design and by default", across the whole of a controller's processing:
 * Pulsar knows that its own pseudonymisation primitive works. It does not know
 * whether the application puts its identifiers through it, and nothing in this
 * tree could find out. That residual is outside what any framework can observe,
 * and the control's requirement text carries it where a reader of the report will
 * see it.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class PseudonymizationProbe implements ControlProbeInterface
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.pseudonymization';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether a synthetic identifier put through the live pseudonymisation service was '
            . 'replaced, resolved back and erased on request, and which table held the mapping.';
    }

    #[Override]
    #[NoDiscard]
    public function requirement(): ControlRequirement
    {
        return ControlRequirement::of(
            required: [
                RequiredFact::essential(
                    ObservationId::IdentifierPseudonymizedAndErased,
                    [
                        'Bind PseudonymizationServiceInterface and ForgetServiceInterface; '
                            . 'SecurityWiring binds both when observability.audit.enabled is true '
                            . 'and the master key loads, because the service seals each subject\'s '
                            . 'salt and both record what they did in the audit trail.',
                        'Read the evidence line for this fact: it names which of replacement, '
                            . 'recording, resolution or erasure failed, and why.',
                    ],
                    'no identifier was replaced, resolved and erased when the service was asked to',
                ),
                RequiredFact::essential(
                    ObservationId::PseudonymTablePersistence,
                    [
                        'Bind PseudonymLookupInterface to FilePseudonymLookup or another table '
                            . 'that survives a restart; InMemoryPseudonymLookup is the development '
                            . 'stub and a pseudonym it minted can never be resolved again.',
                        'If this deployment genuinely processes no personal data, set '
                            . 'scope.processes_personal_data = false in config/compliance.php.',
                    ],
                    'the pseudonym mappings are not held in a table that survives a restart',
                ),
                RequiredFact::contributing(
                    ObservationId::PseudonymizationResolved,
                    [
                        'Bind PseudonymizationServiceInterface so direct identifiers can be '
                            . 'replaced before data is processed or shared.',
                    ],
                    'no pseudonymisation service answered the contract',
                ),
            ],
            supporting: [
                ObservationId::TokenVaultPersistence,
            ],
            scope: ObservationId::ScopeProcessesPersonalData,
            whenUnobserved: [
                'Re-run the report against the deployment itself, so the pseudonymisation '
                    . 'service that serves requests can be exercised rather than inferred.',
            ],
        );
    }
}
