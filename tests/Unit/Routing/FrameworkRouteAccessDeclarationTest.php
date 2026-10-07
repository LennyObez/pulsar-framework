<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\RouteAccess;
use Pulsar\Routing\RouteAccessRegistrar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function bin2hex;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function method_exists;
use function mkdir;
use function preg_match_all;
use function random_bytes;
use function str_replace;
use function strlen;
use function substr;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;

use const PREG_OFFSET_CAPTURE;

/**
 * The enumerating gate: no route under src/ may be registered without saying
 * who is allowed to reach it.
 *
 * An audit of this repository found twenty-five framework registrations made
 * through the router's verb sugar, which attaches no middleware and no
 * attributes. That is not a neutral default. AuthorizationMiddleware
 * default-denies a route with an empty permission list, so an undeclared route
 * is open in a deployment whose pipeline omits that middleware and closed in one
 * that includes it — and which of the two a given install got was settled by
 * middleware ordering rather than by anybody's decision. `/health`,
 * `/_pulsar/diagnostics`, `/_pulsar/rum/collect` and `/api/i18n/{locale}.json` were
 * all in that position.
 *
 * Each was decided and rewritten through {@see RouteAccessRegistrar}. This test
 * is what stops the twenty-sixth from appearing: it scans the shipped source for
 * the bare registration forms and fails on any hit outside the small, named set
 * of places allowed to contain one.
 *
 * A source scan rather than a boot: registering a route is a source-level act,
 * and the wirings that perform it are gated on config, on optional container
 * bindings and on files being present on disk, so no single boot reaches all of
 * them. What must never appear is the SHAPE, and the shape is visible here.
 * {@see \Pulsar\Routing\RouteAccessReporter} is the runtime half of the same
 * rule, and RouteAccessReporterTest covers that half.
 */
#[CoversClass(RouteAccess::class)]
#[CoversClass(RouteAccessRegistrar::class)]
final class FrameworkRouteAccessDeclarationTest extends TestCase
{
    /**
     * Registration forms that declare nothing.
     *
     * The negative lookbehind keeps the code-generation templates out: they
     * carry the same text escaped inside a heredoc, where it is a string
     * destined for an application's own route file rather than a registration
     * this repository performs.
     *
     * `group()` is deliberately absent. It registers no route of its own — it
     * opens a prefix scope and hands a sub-router to a callback, and whatever
     * that callback registers matches one of the verbs below, in whichever file
     * it was written. Listing it here would only flag the forwarding call.
     */
    private const string BARE_REGISTRATION =
        '/(?<!\\\\)\$(?:this->)?router->(?:get|post|put|patch|delete|any|add|resource|apiResource)\s*\(/';

    /**
     * Files permitted to contain the bare form, each for a stated reason.
     *
     * An explicit list rather than a path prefix, so that adding a file to it is
     * a visible act in a diff.
     *
     * @var array<string, string>
     */
    private const array EXEMPT = [
        // The router itself: get()/post()/... are implemented here, and group()
        // re-adds the routes a sub-router collected.
        'src/Routing/Router.php' => 'defines the sugar',
        // Builds the declared Route and is the one place that must call add().
        'src/Routing/RouteAccessRegistrar.php' => 'the declaring registrar itself',
        // Scaffolds a RESTful set from a caller-supplied middleware list; the
        // application that calls it owns the decision.
        'src/Routing/ResourceRegistrar.php' => 'application-owned resource scaffolding',
        // Wraps a router for extensions, forwarding whatever they registered.
        'src/Extensibility/Internal/ScopedRouterProxy.php' => 'forwards an extension registration unchanged',
    ];

    #[Test]
    public function noFrameworkRouteIsRegisteredWithoutDeclaringItsAccess(): void
    {
        $offenders = self::scan(self::sourceRoot(), 'src/');

        self::assertSame(
            [],
            $offenders,
            'Route registrations that declare no access: ' . implode(' | ', $offenders)
                . ' -- register through Pulsar\\Routing\\RouteAccessRegistrar instead:'
                . ' publicRoute(), operatorRoute(), signedRoute() or authenticated().'
                . ' Each takes one line and records both the decision and the reason on the route.',
        );
    }

    /**
     * The scan has to fail on a new bare registration, or it is decoration.
     *
     * A file in the shape of a wiring is written into a temporary tree and the
     * same scanner is pointed at it. The assertion is that the scanner reports
     * it, with file and line, because a gate that cannot fail proves nothing
     * about the registrations it is supposed to be guarding.
     */
    #[Test]
    public function theScanFailsWhenANewRegistrationDeclaresNothing(): void
    {
        $root = sys_get_temp_dir() . '/pulsar_route_scan_' . bin2hex(random_bytes(6));
        @mkdir($root . '/Wiring', 0o755, true);

        $file = $root . '/Wiring/NewlyBareWiring.php';
        file_put_contents($file, self::bareWiringSource());

        $offenders = self::scan($root, 'scanned/');

        self::assertCount(1, $offenders, 'the scanner must report the bare registration');
        self::assertStringContainsString('NewlyBareWiring.php', $offenders[0]);
        self::assertStringContainsString(':9', $offenders[0], 'the offending line must be identified');

        unlink($file);
    }

    /**
     * And it has to accept the declared form, or every wiring would need an
     * exemption and the gate would become its own exemption list.
     */
    #[Test]
    public function theScanAcceptsADeclaredRegistration(): void
    {
        $root = sys_get_temp_dir() . '/pulsar_route_scan_ok_' . bin2hex(random_bytes(6));
        @mkdir($root . '/Wiring', 0o755, true);

        $file = $root . '/Wiring/DeclaredWiring.php';
        file_put_contents($file, self::declaredWiringSource());

        self::assertSame([], self::scan($root, 'scanned/'));

        unlink($file);
    }

    /**
     * Every case of the enum has a registrar method, so a decision can always be
     * expressed. A case nobody can register would push its routes back onto the
     * bare form this whole gate exists to forbid.
     */
    #[Test]
    public function everyAccessCaseHasAWayToBeDeclared(): void
    {
        $methods = [
            RouteAccess::Public->value => 'publicRoute',
            RouteAccess::Operator->value => 'operatorRoute',
            RouteAccess::Signed->value => 'signedRoute',
            RouteAccess::Authenticated->value => 'authenticated',
        ];

        foreach (RouteAccess::cases() as $case) {
            self::assertArrayHasKey($case->value, $methods);
            self::assertTrue(
                method_exists(RouteAccessRegistrar::class, $methods[$case->value]),
                "RouteAccess::{$case->name} has no registrar method",
            );
        }
    }

    private static function bareWiringSource(): string
    {
        return "<?php\n"
            . "\n"
            . "declare(strict_types=1);\n"
            . "\n"
            . "final class NewlyBareWiring\n"
            . "{\n"
            . "    public function wire(object \$router): void\n"
            . "    {\n"
            . "        \$router->get('/_pulsar/newly-bare', [SomeController::class, 'show']);\n"
            . "    }\n"
            . "}\n";
    }

    private static function declaredWiringSource(): string
    {
        return "<?php\n"
            . "\n"
            . "declare(strict_types=1);\n"
            . "\n"
            . "final class DeclaredWiring\n"
            . "{\n"
            . "    public function wire(object \$router, object \$registry): void\n"
            . "    {\n"
            . "        \$routes = new RouteAccessRegistrar(\$router, \$registry);\n"
            . "        \$routes->publicRoute([Method::GET], '/ok', \$handler, 'ok', 'stated reason');\n"
            . "    }\n"
            . "}\n";
    }

    /**
     * @return list<string> "prefix/path.php:LINE" per offending registration
     */
    private static function scan(string $root, string $prefix): array
    {
        $offenders = [];

        if (!is_dir($root)) {
            return $offenders;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = $prefix . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (isset(self::EXEMPT[$relative])) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            if (preg_match_all(self::BARE_REGISTRATION, $contents, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            // PREG_OFFSET_CAPTURE pairs each match with its byte offset; the
            // offset is -1 for a group that did not participate, which cannot
            // happen for group 0 of a match that exists.
            foreach ($matches[0] as [$text, $offset]) {
                if ($offset < 0) {
                    continue;
                }

                $offenders[] = $relative . ':' . (substr_count($contents, "\n", 0, $offset) + 1) . ' ' . $text;
            }
        }

        return $offenders;
    }

    private static function sourceRoot(): string
    {
        return dirname(__DIR__, 3) . '/src';
    }
}
