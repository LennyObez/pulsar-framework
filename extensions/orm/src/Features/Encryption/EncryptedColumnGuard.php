<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Encryption;

use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Exception\EncryptedColumnQueryException;
use Pulsar\Extension\Orm\Domain\EntityMetadata;

use function in_array;

/**
 * Guards against invalid use of encrypted columns in queries.
 *
 * Encrypted columns cannot be used in WHERE or ORDER BY clauses
 * unless they have a blind index configured.
 */
#[Internal]
final class EncryptedColumnGuard
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadataRegistry,
    ) {}

    /**
     * Validate that a column can be used in a WHERE clause.
     *
     * @param class-string $entityClass
     * @throws EncryptedColumnQueryException
     */
    public function guardWhere(string $entityClass, string $column): void
    {
        $metadata = $this->metadataRegistry->get($entityClass);
        $colMeta = $metadata->columnByName($column) ?? $metadata->columnByProperty($column);

        if ($colMeta === null) {
            return;
        }

        if ($colMeta->encrypted && $colMeta->blindIndexColumn === null) {
            throw EncryptedColumnQueryException::filteredWithoutBlindIndex($column);
        }
    }

    /**
     * Validate that a column can be used in an ORDER BY clause.
     *
     * @param class-string $entityClass
     * @throws EncryptedColumnQueryException
     */
    public function guardOrderBy(string $entityClass, string $column): void
    {
        $metadata = $this->metadataRegistry->get($entityClass);
        $colMeta = $metadata->columnByName($column) ?? $metadata->columnByProperty($column);

        if ($colMeta === null) {
            return;
        }

        if ($colMeta->encrypted) {
            throw EncryptedColumnQueryException::orderedEncryptedColumn($column);
        }
    }
}
