<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function array_combine;
use function array_keys;
use function array_values;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;

use const JSON_THROW_ON_ERROR;

/**
 * Turning a stored column back into the value a governance DTO requires.
 *
 * The DTOs in this extension are typed with `non-empty-string` and `list<...>`
 * because a governance record with a blank model id or a null provenance source
 * is not a weaker record, it is a corrupt one. A database column is none of
 * those things until something checks, and the alternative to checking is a
 * suppression comment asserting that the column cannot be empty — which is a
 * claim about a table any operator can write to.
 *
 * So every read goes through here, and a row that cannot produce the value its
 * DTO requires raises rather than being silently repaired. A governance store
 * that quietly substituted a placeholder for a corrupt row would be handing an
 * assessor a record the deployment never wrote.
 */
#[Internal(reason: 'Row marshalling for the durable AI governance stores')]
final readonly class StoredValue
{
    /** The one instant format every AI governance table stores and parses. */
    public const string INSTANT_FORMAT = 'Y-m-d H:i:s';

    /**
     * All static; there is nothing to hold.
     */
    private function __construct() {}

    /**
     * A stored string that a DTO requires to be non-empty.
     *
     * @return non-empty-string
     *
     * @throws AiGovernanceException when the column holds an empty string
     */
    #[NoDiscard]
    public static function required(string $value, string $table, string $column): string
    {
        if ($value === '') {
            throw AiGovernanceException::corruptGovernanceRecord($table, $column);
        }

        return $value;
    }

    /**
     * A stored string that a DTO allows to be absent but not to be blank.
     *
     * An empty string is read as absence rather than raising: a nullable column
     * written through a driver that maps `''` and `NULL` differently is the one
     * case where the two spellings genuinely mean the same thing to the DTO.
     *
     * @return non-empty-string|null
     */
    #[NoDiscard]
    public static function optional(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * An instant, as every table in this extension writes it.
     *
     * MySQL returns a DATETIME(6) column with six zero fraction digits, which are
     * accepted; a non-zero fraction was not written by {@see instantToStore()} and is refused.
     *
     * @throws AiGovernanceException when the column does not parse
     */
    #[NoDiscard]
    public static function instant(string $value, string $table, string $column): DateTimeImmutable
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.0{1,6})?$/', $value, $match) !== 1) {
            throw AiGovernanceException::corruptGovernanceRecord($table, $column);
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!' . self::INSTANT_FORMAT,
            $match[1],
            new DateTimeZone('UTC'),
        );

        if ($parsed === false) {
            throw AiGovernanceException::corruptGovernanceRecord($table, $column);
        }

        return $parsed;
    }

    /**
     * An instant, in the form every table in this extension writes.
     */
    #[NoDiscard]
    public static function instantToStore(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::INSTANT_FORMAT);
    }

    /**
     * A JSON document, as this extension writes one.
     */
    #[NoDiscard]
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * A stored JSON document, decoded to the array it was written from.
     *
     * @return array<array-key, mixed>
     *
     * @throws AiGovernanceException when the column does not hold a JSON array
     */
    #[NoDiscard]
    public static function decodeArray(string $value, string $table, string $column): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw AiGovernanceException::corruptGovernanceRecord($table, $column);
        }

        return $decoded;
    }

    /**
     * A stored JSON document read back as the list of non-empty strings a DTO wants.
     *
     * @return list<non-empty-string>
     *
     * @throws AiGovernanceException when any element is not a non-empty string
     */
    #[NoDiscard]
    public static function stringList(string $value, string $table, string $column): array
    {
        $list = [];

        /** @var mixed $item */
        foreach (self::decodeArray($value, $table, $column) as $item) {
            if (! is_string($item) || $item === '') {
                throw AiGovernanceException::corruptGovernanceRecord($table, $column);
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * A stored JSON document read back as a string-keyed map.
     *
     * @return array<string, mixed>
     *
     * @throws AiGovernanceException when a key is not a string
     */
    #[NoDiscard]
    public static function map(string $value, string $table, string $column): array
    {
        $decoded = self::decodeArray($value, $table, $column);

        // Every key is checked, then the map is rebuilt from the checked keys and
        // the untouched values. Building it by assigning each value into an offset
        // would put a value of unknown type into a typed array one element at a
        // time; combining two lists states the same thing without ever doing that.
        $keys = [];

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                throw AiGovernanceException::corruptGovernanceRecord($table, $column);
            }

            $keys[] = $key;
        }

        return array_combine($keys, array_values($decoded));
    }
}
