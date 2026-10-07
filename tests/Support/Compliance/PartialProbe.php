<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Compliance;

use Override;
use Pulsar\Compliance\Control\ControlProbeInterface;
use Pulsar\Compliance\Control\ControlRequirement;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\RequiredFact;

/**
 * A probe whose control needs two facts, so a deployment holding one of them
 * drives the `--strict` path through a real Partial verdict.
 *
 * The outcome is not stated here and cannot be: the probe names the facts and
 * {@see \Pulsar\Compliance\Control\ProbeVerdict::reach()} decides. A test that
 * could assert against a Partial nobody could reach would be asserting against a
 * verdict no probe can produce.
 */
final readonly class PartialProbe implements ControlProbeInterface
{
    #[Override]
    public function id(): string
    {
        return 'probe.pan_at_rest';
    }

    #[Override]
    public function describe(): string
    {
        return 'Whether the token vault is durable and its cryptography confirmed.';
    }

    #[Override]
    public function requirement(): ControlRequirement
    {
        return ControlRequirement::of([
            RequiredFact::contributing(
                ObservationId::TokenVaultPersistence,
                ['Configure a durable token store for the vault.'],
            ),
            RequiredFact::contributing(
                ObservationId::BackupPrimitiveResolved,
                ['Schedule key rotation for the token vault.'],
                'key rotation could not be confirmed',
            ),
        ]);
    }
}
