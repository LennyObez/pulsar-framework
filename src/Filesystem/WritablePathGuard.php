<?php

declare(strict_types=1);

namespace Pulsar\Filesystem;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Filesystem\Exception\UnsafeWritablePathException;

use function public_path;
use function resolve_path;

/**
 * Resolves a configured framework-state path and refuses it if it lands inside
 * the public document root.
 *
 * base_path() falls back to getcwd() when PULSAR_BASE_PATH is unset, and under
 * PHP-FPM getcwd() is public/ — so a relative default like 'var/cache' would
 * resolve into the webroot and be served to anyone who knows the file name.
 * Cache pools hold serialized application data, and `optimize` writes config and
 * container caches that can carry credentials; logs and sessions are equally
 * sensitive. Rather than write there silently, boot fails loud with an
 * actionable message.
 *
 * Scope: framework STATE only (cache, logs, audit, sessions, feature flags,
 * view cache, the OpenAPI artifact). Public storage disks, CMS media and form
 * uploads are deliberately NOT guarded — serving media from under public/ is a
 * legitimate, documented pattern; those surface through the security-posture
 * check instead. An absolute path outside the project tree (e.g. /mnt/state)
 * passes untouched: the guard rejects containment in the webroot, not mere
 * absoluteness.
 * @api
 */
#[Api(since: '1.0.0')]
final class WritablePathGuard
{
    /**
     * Resolve a configured state path to an absolute one, refusing it if it lies
     * inside the document root.
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
