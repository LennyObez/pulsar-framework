<?php

declare(strict_types=1);

namespace Pulsar\Auth\Identity;

use Pulsar\Api\Api;

/**
 * eIDAS assurance levels per Article 8 of Regulation (EU) No 910/2014.
 *
 * Maps identity verification strength to authentication guard requirements.
 * Low corresponds to single-factor auth, Substantial to multi-factor,
 * and High to hardware-backed or qualified certificate authentication.
 */
#[Api(since: '1.0.0')]
enum LevelOfAssurance: string
{
    /** Single-factor authentication (password only). */
    case Low = 'low';

    /** Multi-factor authentication (password + TOTP/SMS). */
    case Substantial = 'substantial';

    /** Hardware-backed or qualified certificate authentication. */
    case High = 'high';

    /**
     * Whether this level meets or exceeds the required level.
     */
    public function satisfies(self $required): bool
    {
        return $this->numericValue() >= $required->numericValue();
    }

    private function numericValue(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Substantial => 2,
            self::High => 3,
        };
    }
}
