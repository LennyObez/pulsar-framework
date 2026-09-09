<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;
use Pulsar\Support\Coerce;

use function array_key_exists;

/**
 * Configuration for query result caching.
 *
 * The sensitive table and authorization column lists drive automatic cache
 * exclusion: {@see SensitivityMetadata} refuses to cache a query touching a
 * sensitive table or shaped by an authorization column, whatever `enabled` says.
 *
 * ## The regulated preset
 *
 * {@see $regulatedPreset} is the setting the sentence "caching is disabled by
 * default in regulated environments" refers to. That sentence was written in
 * this docblock, in config/database.php and in docs/database.md while `enabled`
 * defaulted to `true` unconditionally and no preset existed anywhere in the
 * tree — three statements of a safeguard that no code implemented, which is the
 * shape ADR-0060 is about: a claim nobody can watch take effect is
 * indistinguishable from no claim.
 *
 * What it does now, exactly:
 *
 *  - `regulated_preset` absent or `false` — `enabled` defaults to `true`, which
 *    is what every existing application already runs on. The preset ADDS a
 *    posture; it does not move the one that was there.
 *  - `regulated_preset` true and no `enabled` key — caching is OFF. Silence
 *    under a regulated posture selects the side on which a stale row scoped to
 *    one caller cannot be served to another.
 *  - `regulated_preset` true and `enabled` written down — the operator's value
 *    wins, in both directions. "Disabled by default" is a default, not a ban:
 *    an application that has decided caching is safe for its workload says so in
 *    a line a reviewer can grep for, and that is the whole difference between
 *    this and a setting that cannot be reached.
 *
 * `array_key_exists` rather than `??` decides which of the last two applies: a
 * present `'enabled' => null` is a value somebody wrote and got wrong, not a key
 * they omitted, and reading it as "omitted" would hand the regulated preset back
 * the default it exists to change. The same reasoning, and the same operator,
 * as {@see \Pulsar\Routing\Binding\ModelBindingConfig::fromArray()}.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class QueryCacheConfig implements ReportsUnknownKeys
{
    /** Keys read from the `query_cache` sub-array of config/database.php. */
    private const array KNOWN_KEYS = [
        'enabled', 'default_ttl_seconds', 'sensitive_table_names', 'authorization_columns', 'regulated_preset',
    ];

    /**
     * @param list<string> $sensitiveTableNames Tables that must never be cached
     * @param list<string> $authorizationColumns Columns that scope cache keys for tenant isolation
     * @param bool $regulatedPreset Whether this deployment answers to a regulated regime.
     *     Its only effect is on what an ABSENT `enabled` key means — see the class
     *     docblock. It is deliberately not consulted anywhere else: a second reading of
     *     the same flag is a second place for the two to disagree.
     * @param list<string> $unknownKeys Keys present in the raw `query_cache` array that
     *     this DTO does not read. Both list keys here are cache-poisoning guards: a
     *     misspelled `sensitive_table_names` silently makes sensitive tables cacheable,
     *     and a misspelled `authorization_columns` drops the per-user key separation.
     */
    public function __construct(
        public bool $enabled = true,
        public int $defaultTtlSeconds = 60,
        public array $sensitiveTableNames = [],
        public array $authorizationColumns = ['user_id', 'tenant_id'],
        public bool $regulatedPreset = false,
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
     * Build from a raw config array.
     *
     * @param array{
     *     enabled?: bool|int|string,
     *     default_ttl_seconds?: int|string,
     *     sensitive_table_names?: list<string>,
     *     authorization_columns?: list<string>,
     *     regulated_preset?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        // strictBool, not a cast: `regulated_preset` selects a posture, and the
        // strings a cast would read as true ("false", "off", "0 ") are exactly the
        // ones an operator types when they mean the opposite. Anything that is not
        // a bool is not an answer, and the non-answer here is the permissive
        // posture the rest of the tree already has — so a mistyped value leaves the
        // deployment where it was rather than silently tightening or loosening it.
        $regulated = Coerce::strictBool($data['regulated_preset'] ?? null);

        return new self(
            // The cast stays on `enabled` on purpose. It is the reading every
            // deployment had before `regulated_preset` existed, and narrowing it here
            // would turn a written-down `'enabled' => 0` into the DEFAULT — which
            // under a non-regulated preset means switching caching ON for an
            // application that had switched it off.
            enabled: array_key_exists('enabled', $data) ? (bool) $data['enabled'] : !$regulated,
            defaultTtlSeconds: (int) ($data['default_ttl_seconds'] ?? 60),
            sensitiveTableNames: $data['sensitive_table_names'] ?? [],
            authorizationColumns: $data['authorization_columns'] ?? ['user_id', 'tenant_id'],
            regulatedPreset: $regulated,
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
