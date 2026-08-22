<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when attempting to use an encrypted column in WHERE/ORDER BY
 * without a blind index.
 * @api
 */
#[Api(since: '1.0.0')]
final class EncryptedColumnQueryException extends OrmException
{
    #[NoDiscard]
    public static function filteredWithoutBlindIndex(string $column): self
    {
        return new self(sprintf(
            'Cannot filter on encrypted column "%s" without a blind index. '
            . 'Add a #[BlindIndex] attribute or use the blind index column directly.',
            $column,
        ));
    }

    #[NoDiscard]
    public static function orderedEncryptedColumn(string $column): self
    {
        return new self(sprintf(
            'Cannot ORDER BY encrypted column "%s". Encrypted data has no meaningful sort order.',
            $column,
        ));
    }
}
