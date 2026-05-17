<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Claim;

use Countable;
use IteratorAggregate;
use NoDiscard;
use Pulsar\Api\Api;
use Traversable;

use function array_filter;
use function array_values;
use function count;

/**
 * Immutable collection of zero-trust claims.
 *
 * Provides typed access and filtering by name, source, and minimum confidence.
 * Produced by signal providers and consumed by the policy engine.
 *
 * @implements IteratorAggregate<int, Claim>
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ClaimSet implements Countable, IteratorAggregate
{
    /** @var list<Claim> */
    private array $claims;

    /**
     * @param list<Claim> $claims
     */
    public function __construct(array $claims = [])
    {
        $this->claims = $claims;
    }

    /**
     * Get all claims with the given name.
     *
     * @return list<Claim>
     */
    #[NoDiscard]
    public function getByName(string $name): array
    {
        return array_values(array_filter(
            $this->claims,
            static fn(Claim $claim): bool => $claim->name === $name,
        ));
    }

    /**
     * Get the first claim with the given name, or null if none exists.
     */
    #[NoDiscard]
    public function first(string $name): ?Claim
    {
        return array_find($this->claims, static fn(Claim $claim): bool => $claim->name === $name);
    }

    /**
     * Filter to claims from a specific source.
     */
    #[NoDiscard]
    public function filterBySource(ClaimSource $source): self
    {
        return new self(array_values(array_filter(
            $this->claims,
            static fn(Claim $claim): bool => $claim->source === $source,
        )));
    }

    /**
     * Filter to claims meeting a minimum confidence threshold.
     */
    #[NoDiscard]
    public function filterByMinConfidence(float $minConfidence): self
    {
        return new self(array_values(array_filter(
            $this->claims,
            static fn(Claim $claim): bool => $claim->confidence >= $minConfidence,
        )));
    }

    /**
     * Check whether a claim with the given name exists in this set.
     */
    #[NoDiscard]
    public function has(string $name): bool
    {
        return array_any($this->claims, static fn(Claim $claim): bool => $claim->name === $name);
    }

    /**
     * Merge another ClaimSet into this one, returning a new set.
     */
    #[NoDiscard]
    public function merge(self $other): self
    {
        return new self([...$this->claims, ...$other->claims]);
    }

    /**
     * Return all claims as an array.
     *
     * @return list<Claim>
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->claims;
    }

    #[NoDiscard]
    public function count(): int
    {
        return count($this->claims);
    }

    /**
     * @return Traversable<int, Claim>
     */
    public function getIterator(): Traversable
    {
        yield from $this->claims;
    }
}
