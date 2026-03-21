<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;
use SodiumException;

use function count;
use function sodium_bin2hex;
use function sodium_memzero;
use function sprintf;
use function strlen;

/**
 * Composite key provider that supports per-subsystem key overrides.
 *
 * When an override key is registered for a given context, it is returned directly
 * instead of deriving from the master key. This enables per-subsystem key rotation:
 * rotating a single subsystem key does not affect other subsystems.
 *
 * When no override exists for a context, derivation delegates to the primary MasterKey,
 * preserving full backward compatibility.
 * @api
 */
#[Api(since: '1.0.0')]
final class CompositeKeyProvider implements KeyProviderInterface
{
    /**
     * @param MasterKey             $primary   The primary master key for KDF derivation
     * @param array<string, string> $overrides Context-keyed map of raw key bytes
     */
    public function __construct(
        private readonly MasterKey $primary,
        private array $overrides = [],
    ) {
        foreach ($overrides as $context => $keyBytes) {
            self::validateOverride($context, $keyBytes);
        }
    }

    public function __destruct()
    {
        foreach ($this->overrides as $context => $keyBytes) {
            $copy = $keyBytes;
            $this->overrides[$context] = '';

            try {
                sodium_memzero($copy);
            } catch (SodiumException) {
                // Best-effort zeroing
            }
        }

        $this->overrides = [];
    }

    /**
     * @return array<string, string>
     * @throws SecurityException
     */
    public function __serialize(): array
    {
        throw SecurityException::serializationForbidden('CompositeKeyProvider');
    }

    /**
     * @param array<string, mixed> $data
     * @throws SecurityException
     */
    public function __unserialize(array $data): void
    {
        throw SecurityException::serializationForbidden('CompositeKeyProvider');
    }

    /**
     * Derive a purpose-specific subkey, or return the override if one exists.
     *
     * When an override is registered for the given context, the override key bytes
     * are returned directly. The override must be exactly $length bytes.
     *
     * @throws InvalidArgumentException If override key length does not match $length
     * @throws SodiumException
     */
    public function deriveSubKey(int $subKeyId, string $context, int $length = SODIUM_CRYPTO_SECRETBOX_KEYBYTES): string
    {
        if (isset($this->overrides[$context])) {
            $override = $this->overrides[$context];

            if (strlen($override) !== $length) {
                throw new InvalidArgumentException(sprintf(
                    'Override key for context "%s" must be exactly %d bytes, got %d',
                    $context,
                    $length,
                    strlen($override),
                ));
            }

            return $override;
        }

        return $this->primary->deriveSubKey($subKeyId, $context, $length);
    }

    /**
     * @throws SodiumException
     */
    public function deriveSubKeyHex(int $subKeyId, string $context, int $length = SODIUM_CRYPTO_SECRETBOX_KEYBYTES): string
    {
        return sodium_bin2hex($this->deriveSubKey($subKeyId, $context, $length));
    }

    /**
     * Prevent key material from leaking in debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'primary' => '[REDACTED]',
            'overrides' => sprintf('[%d override(s): %s]', count($this->overrides), implode(', ', array_keys($this->overrides))),
        ];
    }

    /**
     * Validate that an override key is non-empty and context is a valid string.
     */
    private static function validateOverride(string $context, string $keyBytes): void
    {
        if ($context === '') {
            throw new InvalidArgumentException('Override context must not be empty');
        }

        if ($keyBytes === '') {
            throw new InvalidArgumentException(sprintf(
                'Override key for context "%s" must not be empty',
                $context,
            ));
        }
    }
}
