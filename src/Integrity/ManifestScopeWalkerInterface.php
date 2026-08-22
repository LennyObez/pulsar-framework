<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Turns a scope into the list of files it currently covers.
 *
 * This is the one place that touches the disk on behalf of a manifest, and it is
 * deliberately not part of {@see ManifestScope}. A scope is a signed description
 * — two lists of glob patterns, travelling inside the manifest — and a value that
 * walks a filesystem is no longer a value: it cannot be compared, cached or
 * reasoned about without knowing what the disk looked like at the time.
 *
 * Both sides of the integrity control call the same implementation, and that is
 * load-bearing rather than tidy. The builder decides what to hash; the verifier
 * decides what counts as an addition. Deriving those two answers from separate
 * walks breaks the control in both directions at once — it reports files it never
 * tracked as tampering, and it never looks into directories holding no entry,
 * which is exactly where a removed file goes unnoticed.
 */
#[Api(since: '1.0.0')]
interface ManifestScopeWalkerInterface
{
    /**
     * Every file under the base path that the scope covers.
     *
     * @return list<string> Forward-slash paths relative to the base
     */
    #[NoDiscard]
    public function discover(ManifestScope $scope, string $basePath): array;
}
