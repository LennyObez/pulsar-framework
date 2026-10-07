<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Configuration for the sealed backup primitive, from the `backup` sub-array of
 * `config/resilience.php`.
 *
 * ON BY DEFAULT, AND THAT IS A DELIBERATE DEPARTURE from every other switch in
 * this file. Circuit breakers and health checks default off because a deployment
 * that has not configured thresholds is better off without them. Recovery is not
 * like that: no deployment can assert that recovery does not apply to it — that is
 * why NIST CSF RC.RP could not be scoped out — so a backup primitive that shipped
 * off would leave every default installation in exactly the state
 * `docs/compliance.md` recorded as the residual gap, with the additional dishonesty
 * of a config key implying somebody chose it.
 *
 * What it does NOT do by shipping on is take a backup. Nothing here schedules
 * anything; it binds the service and the plan, so `pulsar backup:run` works and
 * the compliance probe has something real to exercise. When to run it, where to
 * copy the archive and how long to keep it stay the operator's, and
 * {@see \Pulsar\Resilience\Backup\BackupPlan::NOT_COVERED} says so in code.
 *
 * IT STILL FAILS CLOSED. With no `PULSAR_MASTER_KEY` there is no archive key,
 * and {@see \Pulsar\Core\Wiring\BackupWiring} then binds NOTHING rather than
 * writing an unsealed archive — the same posture
 * {@see \Pulsar\Core\Wiring\SecurityWiring} takes for every other at-rest
 * protection. `enabled` cannot switch that around.
 *
 * THE AUDIT TRAIL IS NOT CONFIGURED HERE, on purpose. Its directory is derived
 * from `observability.audit.log_path` — the path the audit sink actually writes
 * to — so an operator who moves the audit log moves the backup with it and cannot
 * leave the two pointing at different places. A second key naming the audit
 * directory would be a way for the archive to silently stop containing the
 * evidence.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class BackupConfig implements ReportsUnknownKeys
{
    /** Keys read from the `backup` sub-array of config/resilience.php. */
    private const array KNOWN_KEYS = ['enabled', 'destination', 'trees'];

    /** The file trees a deployment that has not said otherwise backs up. */
    public const array DEFAULT_TREES = ['files' => 'storage/app'];

    /** Where archives are written when nothing says otherwise. */
    public const string DEFAULT_DESTINATION = 'storage/backups';

    /**
     * @param bool                  $enabled     Whether the primitive is bound at all
     * @param string                $destination Directory archives are written to, relative to
     *        the project root unless absolute. Resolved through
     *        {@see \Pulsar\Filesystem\WritablePathGuard}, so a value inside the document
     *        root fails boot rather than publishing every archive to the internet
     * @param array<non-empty-string, non-empty-string> $trees File trees to include, as
     *        `entry id => path`.
     *        The id becomes the first path segment of the entries the tree contributes
     * @param list<string>          $unknownKeys Keys present in the raw array that this DTO
     *        does not read — `destination` misspelled would silently write archives to the
     *        default location while the operator watched an empty directory
     */
    public function __construct(
        public bool $enabled = true,
        public string $destination = self::DEFAULT_DESTINATION,
        public array $trees = self::DEFAULT_TREES,
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
     * @param array{
     *     enabled?: bool|int|string,
     *     destination?: string,
     *     trees?: array<non-empty-string, non-empty-string>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('BACKUP_ENABLED') !== null
            ? $environment->get('BACKUP_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? true);

        $configured = $data['destination'] ?? null;
        $destination = $environment->get('BACKUP_DESTINATION')
            ?? (is_string($configured) ? $configured : self::DEFAULT_DESTINATION);

        return new self(
            enabled: $enabled,
            destination: $destination === '' ? self::DEFAULT_DESTINATION : $destination,
            trees: self::trees($data),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }

    /**
     * Absent and empty are different answers, and collapsing them would be a bug.
     *
     * No `trees` key at all means the operator has not spoken and the shipped
     * default applies. `'trees' => []` is the operator saying this deployment has
     * no file tree worth archiving beyond the audit trail, which is a legitimate
     * thing to say — a stateless service whose uploads live in object storage —
     * and it is honoured rather than overridden.
     *
     * @param array<string, mixed> $data
     *
     * @return array<non-empty-string, non-empty-string>
     */
    private static function trees(array $data): array
    {
        if (!array_key_exists('trees', $data)) {
            return self::DEFAULT_TREES;
        }

        $configured = $data['trees'];

        if (!is_array($configured)) {
            return [];
        }

        $trees = [];

        /** @var mixed $path */
        foreach ($configured as $id => $path) {
            if (is_string($id) && $id !== '' && is_string($path) && $path !== '') {
                $trees[$id] = $path;
            }
        }

        return $trees;
    }
}
