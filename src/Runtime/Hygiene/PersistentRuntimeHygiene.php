<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Hygiene;

use Override;
use Pulsar\Api\Internal;

/**
 * Composite hygiene profile for persistent runtimes.
 *
 * Composes all individual resetters into a single apply() call.
 */
#[Internal]
final readonly class PersistentRuntimeHygiene implements HygieneProfileInterface
{
    private SuperglobalResetter $superglobalResetter;
    private ErrorStateResetter $errorStateResetter;

    public function __construct()
    {
        $this->superglobalResetter = new SuperglobalResetter();
        $this->errorStateResetter = new ErrorStateResetter();
    }

    #[Override]
    public function apply(): void
    {
        $this->superglobalResetter->reset();
        $this->errorStateResetter->reset();
    }
}
