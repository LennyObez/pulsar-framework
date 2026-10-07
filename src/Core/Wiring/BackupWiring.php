<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\BackupConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Filesystem\WritablePathGuard;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Resilience\Backup\ArchiveSeal;
use Pulsar\Resilience\Backup\BackupDestination;
use Pulsar\Resilience\Backup\BackupPlan;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\Backup\BackupSourceInterface;
use Pulsar\Resilience\Backup\DatabaseBackupSource;
use Pulsar\Resilience\Backup\DatabaseRestoreTarget;
use Pulsar\Resilience\Backup\FileTreeBackupSource;
use Pulsar\Resilience\Backup\FileTreeRestoreTarget;
use Pulsar\Resilience\Backup\RestoreTargetInterface;
use Pulsar\Resilience\Backup\SealedArchiveBackupService;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\KeyProviderInterface;

use function dirname;
use function sprintf;

/**
 * Binds the sealed backup primitive, or binds nothing.
 *
 * IT FAILS CLOSED, and that is the first thing to read. If nothing answers
 * {@see KeyProviderInterface} — which is what a deployment with no
 * `PULSAR_MASTER_KEY` looks like, because {@see SecurityWiring} skips its whole
 * crypto block — this wiring binds NOTHING. It does not fall back to an unsealed
 * archive, and there is no configuration key that makes it. That is the same
 * posture SecurityWiring takes for the encryptor, the token vault and the audit
 * chain, and it is the only posture that keeps the compliance report honest: a
 * deployment without a key must report a recovery gap, not a recovery capability
 * with a footnote.
 *
 * NOTHING IS IN A WIRING CONTRACT, deliberately. {@see \Pulsar\Core\Wiring\Contract\DescribesWiring}
 * declares bindings that must be present on EVERY boot, and these are conditional
 * on a master key. Listing them would fail the contract gate on every keyless
 * development boot, which would teach people to ignore it.
 *
 * WHAT IS COMPOSED, and why each is where it is:
 *
 *  - The DATABASE source and target are built LAZILY, inside the {@see BackupPlan}
 *    closure. Resolving {@see ConnectionInterface} here would open a database
 *    session on every request boot for a primitive almost no request uses — the
 *    eager-resolution mistake ADR-0041 recorded, in a new place.
 *  - The AUDIT source is rooted at the audit sink's OWN log path, read from
 *    `observability.audit.log_path`, rather than at a backup-specific key. Two
 *    keys naming the same file is a way for them to drift, and the drift would be
 *    silent and would only be discovered by an assessor asking where the evidence
 *    went. It is included whenever audit logging is enabled and is not optional.
 *  - The FILE TREES come from `resilience.backup.trees` and are resolved through
 *    {@see WritablePathGuard}, so a tree inside the document root fails boot
 *    rather than being archived and served.
 */
#[Internal]
final readonly class BackupWiring implements ServiceWiringInterface
{
    #[Override]
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(ResilienceConfig::class)) {
            return;
        }

        /** @var ResilienceConfig $resilience */
        $resilience = $repository->get(ResilienceConfig::class);
        $backup = $resilience->backup;

        $container->instance(BackupConfig::class, $backup);

        if (!$backup->enabled) {
            return;
        }

        // Fail closed. No key provider, no seal, no service — see the class note.
        if (!$container->has(KeyProviderInterface::class)) {
            return;
        }

        /** @var KeyProviderInterface $keys */
        $keys = $container->get(KeyProviderInterface::class);

        $service = new SealedArchiveBackupService(new ArchiveSeal($keys));
        $container->instance(SealedArchiveBackupService::class, $service);
        $container->instance(BackupServiceInterface::class, $service);

        $destination = WritablePathGuard::resolveState(
            $backup->destination,
            'resilience.backup.destination',
        );
        $container->instance(BackupDestination::class, new BackupDestination($destination));

        $auditPath = $this->auditPath($repository->has(ObservabilityConfig::class)
            ? $repository->get(ObservabilityConfig::class)
            : null);

        $trees = $this->trees($backup);

        // Lazy: building the plan resolves the database connection, and a request
        // boot must not open one for a command it is not running.
        $container->singleton(
            BackupPlan::class,
            static fn(): BackupPlan => new BackupPlan(
                sources: self::sources($container, $auditPath, $trees),
                targets: self::targets($container, $auditPath, $trees),
            ),
        );
    }

    /**
     * The file trees to archive, resolved and guarded, as `id => absolute path`.
     *
     * The ids stay non-empty all the way from {@see BackupConfig::fromArray()},
     * which drops an entry whose id or path is the empty string. An empty id would
     * make every entry it contributed start with a slash, which
     * {@see \Pulsar\Resilience\Backup\BackupEntry::isSafeName()} refuses — turning a
     * misconfigured tree into a backup that fails halfway rather than a tree that is
     * not there.
     *
     * @return array<non-empty-string, string>
     */
    private function trees(BackupConfig $backup): array
    {
        $resolved = [];

        foreach ($backup->trees as $id => $path) {
            if ($id === BackupPlan::AUDIT_SOURCE_ID) {
                // Refused at boot, and this is the one refusal in this file that is
                // about honesty rather than about paths. The audit source contributes
                // entries under `audit/`, and `pulsar backup:run --require-audit`
                // decides whether the archive carries the trail by asking the manifest
                // whether anything under that prefix was written. A configured tree
                // sharing the id answers that question too -- so a deployment with
                // audit logging switched OFF and a directory called `audit` would pass
                // the check that exists to catch exactly that deployment, and the
                // archive an assessor was told holds the evidence would hold whatever
                // was in that directory instead.
                throw ConfigException::invalidValue(
                    'resilience.backup.trees.' . $id,
                    sprintf(
                        'the id "%s" is reserved for the audit trail, which is taken from '
                            . 'observability.audit.log_path. A tree under that id would make '
                            . '`backup:run --require-audit` report an audit trail the archive does '
                            . 'not carry. Name the tree something else',
                        BackupPlan::AUDIT_SOURCE_ID,
                    ),
                );
            }

            $resolved[$id] = WritablePathGuard::resolveState($path, 'resilience.backup.trees.' . $id);
        }

        return $resolved;
    }

    /**
     * The audit log this deployment actually writes to, or null when audit
     * logging is off.
     *
     * Off is a real state and it is reported rather than papered over: the plan
     * then has no `audit` source, `pulsar backup:run` says the archive carries no
     * audit trail, and an operator who needs one turns audit logging on.
     */
    private function auditPath(?ObservabilityConfig $observability): ?string
    {
        if ($observability === null || !$observability->audit->enabled) {
            return null;
        }

        return WritablePathGuard::resolveState($observability->audit->logPath, 'observability.audit.log_path');
    }

    /**
     * @param array<non-empty-string, string> $trees
     *
     * @return list<BackupSourceInterface>
     */
    private static function sources(ContainerInterface $container, ?string $auditPath, array $trees): array
    {
        $sources = [];

        if ($container->has(ConnectionInterface::class)) {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            $sources[] = new DatabaseBackupSource($connection, new DatabaseIntrospector($connection));
        }

        if ($auditPath !== null) {
            $sources[] = new FileTreeBackupSource(
                BackupPlan::AUDIT_SOURCE_ID,
                $auditPath,
                'the tamper-evident audit trail as the audit sink writes it',
            );
        }

        foreach ($trees as $id => $path) {
            $sources[] = new FileTreeBackupSource($id, $path, 'a configured file tree');
        }

        return $sources;
    }

    /**
     * @param array<non-empty-string, string> $trees
     *
     * @return list<RestoreTargetInterface>
     */
    private static function targets(ContainerInterface $container, ?string $auditPath, array $trees): array
    {
        $targets = [];

        if ($container->has(ConnectionInterface::class)) {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            // replaceExisting stays false here: the bound plan is the SAFE one, and
            // `backup:restore --replace` composes its own target. A destructive
            // default sitting in the container is one resolve away from a command
            // that did not mean to ask for it.
            $targets[] = new DatabaseRestoreTarget($connection);
        }

        if ($auditPath !== null) {
            // Rooted at the audit log's DIRECTORY: the source contributed the file
            // under its own basename, so the entry lands back on the same path.
            $targets[] = new FileTreeRestoreTarget(BackupPlan::AUDIT_SOURCE_ID, dirname($auditPath));
        }

        foreach ($trees as $id => $path) {
            $targets[] = new FileTreeRestoreTarget($id, $path);
        }

        return $targets;
    }
}
