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
 * Whether the token vault actually renders a value unreadable where it rests.
 *
 * ADR-0041 caught PCI Req 3.4 reporting Implemented because `DatabaseTokenStore`
 * existed in the source tree, and prescribed the fix: check which store
 * RESOLVED. This probe's first version did exactly that, and adversarial review
 * found the same defect one layer down — the store resolved to the accepted,
 * durable implementation against a database holding no `token_vault` table, so
 * the vault would have thrown on its first use while the evidence read
 * `TokenStoreInterface -> DatabaseTokenStore` and the control read Satisfied.
 * "The class is bound" is "the class exists" with a longer sentence.
 *
 * So the deciding fact is now {@see ObservationId::TokenVaultRendersUnreadable}:
 * a synthetic value tokenized, persisted, read back from the STORE (not through
 * the service that wrote it), detokenized, and removed again. Nothing short of
 * using the vault distinguishes one that works.
 *
 * WHAT THIS PROBE DOES NOT AND CANNOT SEE, stated because the control's own
 * words are "anywhere it is stored": Pulsar does not know where an application
 * stores account data and has no way to find out. A deployment can hold a
 * perfectly working vault and still write raw PANs into an orders table. That
 * residual is not a weakness of this probe to be tightened later — it is outside
 * what any framework can observe — and it is carried by the operator-responsibility
 * control beside this one in {@see \Pulsar\Compliance\Frameworks\PciDssMapping},
 * which names the storage inventory an assessor must be shown.
 *
 * Declared as its own class rather than through {@see CapabilityProbe} because
 * its failure modes are not interchangeable and must not print the same way. A
 * vault that forgets its mappings is not a weaker vault: the tokens resolve to
 * nothing and the values they replaced are unrecoverable. Both of the deciding
 * facts are therefore {@see RequiredFact::essential()} — absent either one, no
 * amount of cryptography beside them makes the control hold — while the
 * availability of the primitives is {@see RequiredFact::contributing()}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class PanAtRestProbe implements ControlProbeInterface
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.pan_at_rest';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether a synthetic value put through the live token vault came back '
            . 'unreadable at rest and recoverable by its holder, and which store held it.';
    }

    #[Override]
    #[NoDiscard]
    public function requirement(): ControlRequirement
    {
        return ControlRequirement::of(
            required: [
                RequiredFact::essential(
                    ObservationId::TokenVaultRendersUnreadable,
                    [
                        'Create the token_vault table the store writes to; a bound '
                            . 'DatabaseTokenStore whose table does not exist throws on the first '
                            . 'tokenize() and renders nothing unreadable.',
                        'Read the evidence line for this fact: it names which of tokenize, '
                            . 'persist, detokenize or remove failed, and why.',
                    ],
                    'the vault did not render a value unreadable when it was asked to',
                ),
                RequiredFact::essential(
                    ObservationId::TokenVaultPersistence,
                    [
                        'Configure a database connection so TokenStoreInterface resolves to '
                            . 'DatabaseTokenStore rather than InMemoryTokenStore (ADR-0041).',
                        'If this deployment genuinely handles no PAN, set '
                            . 'scope.stores_cardholder_data = false in config/compliance.php.',
                    ],
                    'tokens are not held in a store that survives a restart',
                ),
                RequiredFact::contributing(
                    ObservationId::CryptographicCapability,
                    ['Install ext-sodium and deploy against OpenSSL providing AES-256-GCM.'],
                    'the cryptography the vault depends on could not be confirmed available',
                ),
            ],
            scope: ObservationId::ScopeStoresCardholderData,
            whenUnobserved: [
                'Re-run the report against the deployment itself, so the token store that '
                    . 'serves requests can be exercised rather than inferred.',
            ],
        );
    }
}
