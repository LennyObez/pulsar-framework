<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use function in_array;

use Pulsar\Api\Internal;
use SodiumException;

/**
 * Key ring backed by environment-derived keys (current + optional previous).
 *
 * Default implementation of KeyRingInterface. Reads current and previous
 * audit keys from a MasterKey, indexed by their computed kid.
 */
#[Internal]
final readonly class EnvKeyRing implements KeyRingInterface
{
    /**
     * @param array<string, string> $keys   Map of kid => raw key bytes
     * @param list<string>          $aliases Internal aliases excluded from all()
     */
    public function __construct(
        private array $keys,
        private array $aliases = [],
    ) {}

    public function keyFor(string $kid): ?string
    {
        return $this->keys[$kid] ?? null;
    }

    /**
     * @return iterable<string, string>
     */
    public function all(): iterable
    {
        foreach ($this->keys as $kid => $key) {
            if (!in_array($kid, $this->aliases, true)) {
                yield $kid => $key;
            }
        }
    }

    /**
     * Build from MasterKey: registers current key (and previous if available) by kid.
     *
     * @throws SodiumException
     */
    public static function fromMasterKey(MasterKey $masterKey, int $subKeyId, string $context): self
    {
        $keys = [];

        $currentKey = $masterKey->deriveSubKey($subKeyId, $context);
        $currentKid = $masterKey->keyId($subKeyId, $context);
        $keys[$currentKid] = $currentKey;

        $previousKey = $masterKey->derivePreviousSubKey($subKeyId, $context);
        if ($previousKey !== null) {
            $previousKid = $masterKey->previousKeyId($subKeyId, $context);
            if ($previousKid !== null) {
                $keys[$previousKid] = $previousKey;
            }
        }

        return new self($keys);
    }
}
