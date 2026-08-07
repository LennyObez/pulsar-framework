<?php

declare(strict_types=1);

namespace Pulsar\Filesystem;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Filesystem\Exception\UnsafeWritablePathException;

use function public_path;
use function resolve_path;

/**
 * Resolves a configured writable path and refuses it if it lands inside the
 * public document root.
 *
 * A relative config value like 'var/cache' or 'storage/app' says nothing about
 * where it lands; point it at the document root and everything written there is
 * served to anyone who knows the file name. Cache pools hold serialized
 * application data, and `optimize` writes config and container caches that can
 * carry credentials; logs and sessions are equally sensitive, and a storage disk
 * holds whatever the application was handed. Rather than write there silently,
 * boot fails loud with an actionable message.
 *
 * Scope: framework STATE written at boot or per request (cache, logs, audit,
 * sessions, feature flags, the compiled view cache), plus every local
 * storage-disk root, which {@see \Pulsar\Core\Wiring\StorageWiring} resolves
 * through here at wiring time. One exemption exists and it is explicit: a disk
 * whose `visibility` is `public` in config/storage.php is the operator declaring
 * it is meant to be served, and the wiring resolves that root without the
 * containment check. Operator-directed build artifacts produced by CLI commands
 * (the OpenAPI spec, the integrity manifest) are out of scope: those are
 * generated on demand to a location the operator chooses, and an OpenAPI spec is
 * often meant to be served. An absolute path outside the project tree (e.g.
 * /mnt/state) passes untouched: the guard rejects containment in the webroot, not
 * mere absoluteness.
 *
 * Not in scope, and it should be: the form extension's upload directory. Every
 * extension layer in tools/php/deptrac.yaml is denied a dependency on
 * Pulsar\Filesystem, so `UploadConfig` cannot reach this class. Open gap in
 * docs/security/asvs-l2-matrix.md.
 *
 * What it does not catch: a wrong project root. base_path() falls back to
 * getcwd() when PULSAR_BASE_PATH is unset, and under PHP-FPM getcwd() is the
 * directory of the front controller — so the configured path and public_path()
 * shift together and containment still reads "outside". Setting PULSAR_BASE_PATH
 * to the project root is the deployment contract this guard is measured against,
 * not something it can verify; tests/Unit/Filesystem/WritablePathGuardTest.php
 * pins that limit so nobody reads more into a passing boot than is there.
 * @api
 */
#[Api(since: '1.0.0')]
final class WritablePathGuard
{
    /**
     * Resolve a configured writable path to an absolute one, refusing it if it
     * lies inside the document root.
     *
     * Callers must use the returned value rather than the configured one: a
     * consumer handed the raw relative string resolves it against its own process
     * CWD and can write somewhere other than the location just judged safe.
     *
     * @param string $configured The raw config value (relative or absolute).
     * @param string $configKey  Dotted config key, used only in the error message.
     *
     * @throws UnsafeWritablePathException When the resolved path is inside public/.
     */
    #[NoDiscard]
    public static function resolveState(string $configured, string $configKey): string
    {
        $resolved = resolve_path($configured);
        $documentRoot = public_path();

        if (SafePath::isWithin($resolved, $documentRoot)) {
            throw UnsafeWritablePathException::insideDocumentRoot($configKey, $configured, $resolved, $documentRoot);
        }

        return $resolved;
    }
}
