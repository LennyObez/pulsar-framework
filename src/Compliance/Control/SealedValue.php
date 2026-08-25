<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use Pulsar\Api\Internal;

use function count;

/**
 * Closes the four doors into a value that are not constructors.
 *
 * A private constructor guarded by {@see MeasuringComponent} settles who may CALL
 * a class into existence. It settles nothing about the three other ways PHP will
 * hand you one, and each of the three is a complete bypass:
 *
 *  - `unserialize('O:41:"Pulsar\Compliance\Control\Observation":6:{...}')` builds
 *    an instance with every property set to whatever the string says, without
 *    running any constructor. A Measured, present observation about a deployment
 *    nobody looked at is 200 bytes of text.
 *  - `var_export()` output is executable PHP, and `__set_state()` is the callback
 *    that turns it back into an object. A report exported for an assessor and
 *    read back in is a laundering path if that callback builds anything.
 *  - `clone` copies a value the caller already legitimately holds. On a readonly
 *    class that is harmless today, but PHP keeps adding ways to modify during a
 *    clone, and a vocabulary whose whole claim is "this value came from a
 *    measurement" must not have a copy path that outlives the reason to trust it.
 *
 * All four refuse, loudly, with the same exception the rest of the seal uses.
 * Nothing in the compliance subsystem serialises a fact — the report renderers
 * read observations and write strings — so refusing costs nothing that anything
 * here does.
 *
 * Reflection is not on this list, and that is deliberate: see
 * {@see MeasuringComponent} for what the seal is and is not.
 */
#[Internal(reason: 'The seal on every compliance value; not part of the public vocabulary')]
trait SealedValue
{
    /**
     * @throws InadmissibleEvidenceException always
     */
    public function __wakeup(): void
    {
        throw InadmissibleEvidenceException::forDeserialization(self::class, 0);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws InadmissibleEvidenceException always
     */
    public function __unserialize(array $data): void
    {
        throw InadmissibleEvidenceException::forDeserialization(self::class, count($data));
    }

    /**
     * @param array<array-key, mixed> $state
     *
     * @throws InadmissibleEvidenceException always
     */
    public static function __set_state(array $state): never
    {
        throw InadmissibleEvidenceException::forDeserialization(self::class, count($state));
    }

    /**
     * @throws InadmissibleEvidenceException always
     */
    public function __clone()
    {
        throw InadmissibleEvidenceException::forCloning(self::class);
    }
}
