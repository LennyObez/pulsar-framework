<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the audit trail is written somewhere durable AND its HMAC chain
 * still verifies.
 *
 * Cited by ISO 27001 A.8.15, PCI-DSS Req 10.2, NIST CSF DE.AE, SOC 2 CC7.2
 * and ISO 42001 Clause 9.2. Before this probe each of those stated the same
 * claim in different prose and could disagree; now they all rest on one
 * recomputation of one chain.
 *
 * Chain verification is the strongest evidence anywhere in this codebase and
 * the only fact proved by cryptography rather than by inspection. What it
 * establishes, precisely: modification, reordering and injection by recomputing
 * every signature; removal — from the end of the register as well as from the
 * middle — by comparing the register against the height its anchor attests.
 *
 * The second half is worth spelling out because it is the half a hash chain
 * cannot do. Chaining each record to its predecessor proves nothing about
 * records deleted from the END: what remains is a shorter chain in which every
 * surviving link verifies. This sentence used to claim truncation was detectable
 * while the verifier reported a truncated register as valid. It is detectable
 * now because {@see \Pulsar\Compliance\Evidence\EvidenceChainHead} attests the
 * height out of band — and a register held in a store that cannot keep an anchor
 * reports {@see \Pulsar\Compliance\Verification\EvidenceChainVerdict::Unanchored}
 * rather than passing on a property nobody checked.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class TamperEvidentAuditProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.tamper_evident_audit';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Which audit sink persists the trail, and whether its HMAC chain still verifies.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::AuditSinkResolved,
            ObservationId::AuditChainVerified,
        ];
    }

    /**
     * @return list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function supporting(): array
    {
        return [
            ObservationId::EvidenceStoreResolved,
            ObservationId::MasterKeyMaterial,
        ];
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    #[Override]
    #[NoDiscard]
    protected function remediations(): array
    {
        return [
            'Bind an AuditSinkInterface that persists outside the process '
                . '(AuditFileSink), so the trail survives the request that wrote it.',
            'Set PULSAR_MASTER_KEY: without it no evidence key is derived and the '
                . 'chain has nothing to sign with.',
            'Record at least one verification run, so the chain has a link to verify; '
                . 'an empty chain proves nothing.',
            'Keep the evidence register in a store that persists its anchor '
                . '(FileEvidenceStore writes one beside the register): without an attested '
                . 'height, records removed from the end of the chain are undetectable and '
                . 'the register cannot be reported as complete.',
        ];
    }
}
