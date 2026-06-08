<?php

declare(strict_types=1);

namespace Pulsar\Dev;

use Closure;
use Pulsar\Api\Internal;

/**
 * Configuration DTO for dev server routers.
 *
 * Each extension creates a DevServerConfig describing its asset paths,
 * migration dirs, identity needs, and container bindings: then passes
 * it to DevServerBootstrap::run().
 */
#[Internal(reason: 'Dev server implementation detail; not part of public API')]
final readonly class DevServerConfig
{
    /**
     * @param string $extensionName Short name for logging (e.g., 'admin', 'cms')
     * @param array<string, list<string>> $assetPrefixes URL prefix => list of filesystem dirs to search
     * @param list<string> $templatePaths Paths for ViewConfig (Pulse template engine)
     * @param array<string, object> $containerBindings Pre-boot container instance bindings
     * @param bool $injectDevIdentity Whether to inject a dev admin identity
     * @param list<string> $devIdentityRoles Roles for the injected dev identity
     * @param bool $autoMigrate Whether to auto-migrate on first request
     * @param list<string> $migrationDirs Directories to scan for migration files
     * @param list<string> $setupSql Additional SQL statements to run before migrations
     * @param list<Closure> $seeders Callables that receive the DB connection for seeding
     * @param list<string> $criticalTables Tables that must exist before writing the schema marker
     * @param array<string, string> $redirects Path redirects (from => to)
     */
    public function __construct(
        public string $extensionName,
        public array $assetPrefixes = [],
        public array $templatePaths = [],
        public array $containerBindings = [],
        public bool $injectDevIdentity = true,
        public array $devIdentityRoles = ['admin', 'editor', 'moderator'],
        public bool $autoMigrate = true,
        public array $migrationDirs = [],
        public array $setupSql = [],
        public array $seeders = [],
        public array $criticalTables = [],
        public array $redirects = [],
    ) {}
}
