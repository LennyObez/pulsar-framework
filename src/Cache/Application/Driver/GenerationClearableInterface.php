<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Internal;

/**
 * Marks a driver on which a generation-scoped prefix clear is SAFE.
 *
 * Some backends cannot enumerate keys, so a prefix-scoped {@see clear()} cannot
 * delete a pool's keys one by one (unlike {@see PrefixClearableInterface}).
 * {@see \Pulsar\Cache\Application\Prefix\GenerationScopedCacheDecorator} clears
 * such a pool by bumping a generation counter, which orphans the previous
 * generation's keys instead of deleting them.
 *
 * That is only acceptable where the backend RECLAIMS the orphans on its own —
 * an LRU/eviction store like Memcached, where superseded keys are pushed out as
 * memory pressure rises. On a persistent store with no eviction (filesystem,
 * database), orphaned generations would accumulate forever, so those drivers
 * must NOT declare this and instead keep the fail-loud behaviour. The marker is
 * therefore a structural promise about reclamation, not merely about the
 * increment primitive: a driver declaring it MUST also support atomic
 * increment (the counter bump must not lose updates under concurrency).
 */
#[Internal]
interface GenerationClearableInterface {}
