<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;
use Pulsar\Extensibility\ExtensionManifest;
use ReflectionClass;

use function class_exists;
use function in_array;
use function interface_exists;
use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strrpos;
use function substr;

/**
 * What each loaded extension publishes to the others.
 *
 * ## The question this answers
 *
 * Deny-by-default is a rule about the HOST's service graph:
 * {@see ScopedContainerProxy::assertCanResolve()} refuses an id the framework's
 * restriction map does not classify. An extension's own types escape that
 * through `isOwnCode()`, because an extension's own vocabulary was never part
 * of the host's graph.
 *
 * A PEER's type is neither. `pulsar/forum` registers its threads and posts as
 * back-office resources through `pulsar/admin`'s `AdminGateway`, and its member
 * pages as account sections through `pulsar/cms`'s `AccountSectionRegistry`.
 * Both calls are the documented way those extensions are extended, both are
 * guarded by `has()` so the consumer degrades when the provider is absent, and
 * both were refused — because a framework-owned map cannot enumerate every
 * extension's contracts, and should not try: the set changes with what is
 * installed, and naming one extension's types in a framework file inverts the
 * dependency ADR-0004 exists to keep pointing the other way.
 *
 * So the extensions answer for themselves. A manifest already declares a
 * `provides.services` list; this makes that list load-bearing. A type is
 * reachable from another extension's scope when its declaring FILE sits inside
 * some loaded extension's directory AND that extension's manifest names it.
 * Everything else an extension ships — `Internal\`, controllers, entities —
 * stays private to it, which is what ADR-0002 and ADR-0009 already say about
 * module-private code.
 *
 * ## Why the file decides and the name does not
 *
 * The same reason `isOwnCode()` uses a path. A manifest entry is a claim by
 * whoever wrote it, so on its own it would let `acme/evil` publish
 * `Pulsar\Security\Crypto\MasterKey` by typing the name. It cannot publish what
 * it does not ship: a type is attributed to the extension whose directory
 * physically contains its declaring file, and the manifest consulted is THAT
 * extension's. An entry naming someone else's class matches nothing, and
 * Composer owns the framework's `src/`, so no framework type resolves inside an
 * extension directory at all.
 *
 * Nor is the CONSUMER asked to declare anything. `suggests` and `requires` are
 * the consumer's own unauthenticated claim about itself, exactly like the
 * manifest `trust_tier` that {@see \Pulsar\Extensibility\ExtensionBootstrap}
 * refuses to treat as a grant: a rule requiring the consumer to declare a
 * dependency is satisfied by the consumer adding a line to its own file, so it
 * would constrain the honest and nobody else. The decision belongs to the party
 * whose service is at stake, and only to it.
 *
 * ## What it does not decide
 *
 * Reachability, not privilege. A peer's service still leaves the scope through
 * `contain()` like any other value, and the consuming extension's tier still
 * governs everything it does with what it got.
 *
 * @internal Not part of the public API
 */
final readonly class ExtensionSurfaces
{
    /**
     * @param list<array{root: string, published: list<string>}> $extensions One
     *        entry per loaded extension: its real directory path, and the
     *        service names its manifest publishes.
     */
    public function __construct(private array $extensions = []) {}

    /**
     * Build from the manifests of every loaded extension.
     *
     * @param iterable<ExtensionManifest> $manifests
     */
    #[NoDiscard]
    public static function fromManifests(iterable $manifests): self
    {
        $extensions = [];

        foreach ($manifests as $manifest) {
            $root = $manifest->path === '' ? false : realpath($manifest->path);

            if ($root === false) {
                // No directory, so no file can be attributed to it. An
                // extension added programmatically — as tests do — publishes
                // nothing, which is the same answer `isOwnCode()` gives when it
                // has no path to compare against, and for the same reason.
                continue;
            }

            $extensions[] = [
                'root' => self::normalise($root),
                'published' => $manifest->provides->services,
            ];
        }

        return new self($extensions);
    }

    /**
     * Whether some loaded extension publishes this type as part of its declared
     * surface.
     *
     * False for a type no extension ships, for one shipped but not declared, and
     * for a manifest entry naming a type its own extension does not ship.
     */
    #[NoDiscard]
    public function isPublished(string $type): bool
    {
        $file = self::declaringFile($type);

        if ($file === null) {
            return false;
        }

        foreach ($this->extensions as $extension) {
            if (!str_starts_with($file, $extension['root'] . '/')) {
                continue;
            }

            // The full name or the class basename, because `provides.services`
            // is written by hand and every bundled manifest spells it short.
            // Both are matched against ONE extension's list — the one whose
            // directory holds the file — so a short name cannot be claimed by
            // an extension that merely shares the basename.
            return in_array($type, $extension['published'], true)
                || in_array(self::shortName($type), $extension['published'], true);
        }

        return false;
    }

    /**
     * The file a type is declared in, normalised, or null when the type does not
     * resolve or is not declared in PHP source (an internal class).
     */
    private static function declaringFile(string $type): ?string
    {
        if (!class_exists($type) && !interface_exists($type)) {
            return null;
        }

        $declaredIn = new ReflectionClass($type)->getFileName();

        if ($declaredIn === false) {
            return null;
        }

        $file = realpath($declaredIn);

        return $file === false ? null : self::normalise($file);
    }

    private static function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function shortName(string $type): string
    {
        $separator = strrpos($type, '\\');

        return $separator === false ? $type : substr($type, $separator + 1);
    }
}
