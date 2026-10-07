<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring\Contract;

use Pulsar\Api\Api;

/**
 * A wiring's self-description: the config it reads and the bindings it provides,
 * requires, and optionally uses.
 *
 * Makes a component's wiring contract DISCOVERABLE — at runtime (diagnostics,
 * health) and in generated docs — so an integrator never has to read `src/` to
 * learn how to wire a feature, and so an intra-framework gap (a required binding
 * no shipped wiring provides, or an optional binding that silently disables a
 * feature) is caught instead of buried.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class WiringContract
{
    /**
     * @param string                   $component   Stable component name (e.g. 'anti-spam')
     * @param class-string|null        $configClass Config DTO whose docblock documents the keys (reflected for the config reference)
     * @param string|null              $configFile  Config file the wiring loads (e.g. 'anti-spam.php')
     * @param list<class-string>       $provides    Container bindings this wiring registers
     * @param list<class-string>       $requires    Bindings it MUST resolve to function at all
     * @param list<OptionalBinding>    $optional    Bindings it optionally uses, each gating a feature
     */
    public function __construct(
        public string $component,
        public ?string $configClass = null,
        public ?string $configFile = null,
        public array $provides = [],
        public array $requires = [],
        public array $optional = [],
    ) {}
}
