<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;
use Pulsar\Support\Coerce;

/**
 * Consent tracking configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConsentConfig implements ReportsUnknownKeys
{
    /** Keys read from the `consent` sub-array of config/data_protection.php. */
    private const array KNOWN_KEYS = ['require_explicit', 'purposes'];

    /**
     * @param bool $requireExplicit
     * @param list<string> $purposes
     * @param list<string> $unknownKeys Keys present in the raw `consent` array that this
     *     DTO does not read — a misspelled `require_explicit` silently reverts to
     *     requiring explicit consent (or not) against the operator's intent.
     */
    public function __construct(
        public bool $requireExplicit = true,
        public array $purposes = [],
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            requireExplicit: Coerce::strictBool($data['require_explicit'] ?? null, true),
            purposes: Coerce::listOfString($data['purposes'] ?? null),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
