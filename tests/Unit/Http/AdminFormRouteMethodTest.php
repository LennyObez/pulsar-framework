<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\Cms\CmsExtension;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Controller\Admin\LiveCssController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ThemeController;
use Pulsar\Extension\Forum\ForumExtension;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\PaymentsExtension;
use Pulsar\Http\Method;
use Pulsar\Routing\Router;
use Pulsar\Tests\Support\Http\DiscoveredForm;
use Pulsar\Tests\Support\Http\FormActionScanner;
use Pulsar\Tests\Support\Http\FormTargetSegment;
use stdClass;

use function array_keys;
use function array_map;
use function dirname;
use function implode;
use function in_array;
use function sprintf;

/**
 * Every admin form must be able to reach the write endpoint it submits to.
 *
 * A browser form can emit GET or POST and nothing else, and Pulsar carries no
 * server-side method-spoofing reader (the `_method` convention was removed once
 * it was found to have none). A write route registered only for PUT or DELETE
 * is therefore unreachable from the admin UI: the router answers 405 and the
 * button has never worked. That is what this test forbids.
 *
 * Scope, stated so the gaps are visible rather than silent:
 *
 * - Only forms whose target starts with `/admin/` are checked. Public-site
 *   forms are the same class of defect but a different owner.
 * - A form is checked against a route only when the route was written for that
 *   URL shape — same segment count, literals equal, parameters sitting where
 *   the template interpolates ({@see DiscoveredForm::isServedBy()}). A form
 *   posting to `/admin/cms/media/bulk` is matched by `/admin/cms/media/{id}`
 *   at runtime and does answer 405, but `{id}` is swallowing the literal word
 *   `bulk`: the endpoint the form wants was never written, which is a missing
 *   feature and not a refused method.
 * - The path must already carry a route that mutates. A path answering only
 *   GET/HEAD/OPTIONS is a read endpoint, and a form aimed at it is likewise
 *   asking for a handler nobody wrote.
 *
 * Both remaining classes — no route at all, and a read-only path — are real
 * defects that this test deliberately does not claim to cover.
 */
#[CoversNothing]
final class AdminFormRouteMethodTest extends TestCase
{
    /**
     * Methods that only read. A path served by nothing else is a read endpoint,
     * and a form aimed at it wants a handler that was never written.
     *
     * @var list<Method>
     */
    private const array SAFE_METHODS = [Method::GET, Method::HEAD, Method::OPTIONS];

    /**
     * @return iterable<string, array{string}>
     */
    public static function bundledExtensionProvider(): iterable
    {
        yield 'cms' => ['cms'];
        yield 'forum' => ['forum'];
        yield 'payments' => ['payments'];
    }

    #[Test]
    #[DataProvider('bundledExtensionProvider')]
    public function everyAdminFormReachesItsWriteRoute(string $extension): void
    {
        $router = self::bootBundledExtensions();
        $forms = new FormActionScanner()->scanDirectory(
            self::repositoryRoot() . '/extensions/' . $extension,
        );

        $checked = 0;
        $failures = [];

        foreach ($forms as $form) {
            if (!$form->isAdminTarget()) {
                continue;
            }

            $allowed = self::methodsServing($router, $form);

            if ($allowed === [] || !self::hasWriteMethod($allowed)) {
                continue;
            }

            $checked++;

            if (in_array($form->method, $allowed, true)) {
                continue;
            }

            $failures[] = sprintf(
                '%s:%d submits %s to %s, which allows only [%s]',
                $form->file,
                $form->line,
                $form->method->value,
                $form->path(),
                implode(', ', array_map(static fn(Method $m): string => $m->value, $allowed)),
            );
        }

        self::assertGreaterThan(
            0,
            $checked,
            sprintf('No admin form in "%s" reached a write route — the scanner found nothing to check.', $extension),
        );
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * The check must be able to fail: a save form aimed at a PUT-only route is
     * exactly the shape this test exists to catch, so plant one and watch it be
     * refused.
     */
    #[Test]
    public function aFormAimedAtAPutOnlyRouteIsRejected(): void
    {
        $router = new Router();
        $router->put('/admin/widgets/{id}', [stdClass::class, 'update'], 'widgets.update');

        $form = new DiscoveredForm(
            file: 'planted/widget-form.pulse.php',
            line: 1,
            method: Method::POST,
            action: '/admin/widgets/{{ $widget[\'id\'] }}',
            segments: [
                new FormTargetSegment('admin', false),
                new FormTargetSegment('widgets', false),
                new FormTargetSegment('1', true),
            ],
        );

        $allowed = self::methodsServing($router, $form);

        self::assertTrue($form->isAdminTarget());
        self::assertTrue(self::hasWriteMethod($allowed), 'The planted route must count as a write route.');
        self::assertNotContains(Method::POST, $allowed, 'The planted form must not be reachable.');
    }

    /**
     * The literal-segment rule must hold: a form target whose literal lands on a
     * route parameter belongs to an endpoint that was never written, so the
     * route does not count as serving it.
     */
    #[Test]
    public function aLiteralSegmentCapturedByARouteParameterDoesNotCount(): void
    {
        $router = new Router();
        $router->delete('/admin/widgets/{id}', [stdClass::class, 'delete'], 'widgets.delete');

        $form = new DiscoveredForm(
            file: 'planted/widget-bulk.pulse.php',
            line: 1,
            method: Method::POST,
            action: '/admin/widgets/bulk',
            segments: [
                new FormTargetSegment('admin', false),
                new FormTargetSegment('widgets', false),
                new FormTargetSegment('bulk', false),
            ],
        );

        self::assertSame([], self::methodsServing($router, $form));
    }

    /**
     * Whether any of these methods mutates, i.e. the path is a write endpoint.
     *
     * @param list<Method> $allowed
     */
    private static function hasWriteMethod(array $allowed): bool
    {
        foreach ($allowed as $method) {
            if (!in_array($method, self::SAFE_METHODS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every method offered by the routes actually written for this form's URL.
     *
     * @return list<Method>
     */
    private static function methodsServing(Router $router, DiscoveredForm $form): array
    {
        $allowed = [];

        foreach ($router->routes as $route) {
            if (!$form->isServedBy($route->path)) {
                continue;
            }

            foreach ($route->methods as $method) {
                $allowed[$method->value] = $method;
            }
        }

        $ordered = [];

        foreach (array_keys($allowed) as $value) {
            $ordered[] = $allowed[$value];
        }

        return $ordered;
    }

    /**
     * Boot the bundled extensions whose admin UIs share the `/admin` namespace.
     *
     * All three go into one router because that is how a deployment sees them:
     * a form in one extension can be shadowed by a route in another.
     */
    private static function bootBundledExtensions(): Router
    {
        $router = new Router();
        $container = new Container();
        $container->instance(CmsConfig::class, CmsConfig::fromArray([]));
        $container->instance(PaymentsConfig::class, PaymentsConfig::fromArray([]));

        // The CMS registers its theme and Live CSS admin routes only when those
        // controllers are bound. Binding a factory is enough: route registration
        // asks `has()` and never resolves them.
        foreach ([ThemeController::class, LiveCssController::class] as $optional) {
            $container->bind($optional, static fn(): object => new stdClass());
        }

        new CmsExtension()->boot($container, $router);
        new ForumExtension()->boot($container, $router);
        new PaymentsExtension()->boot($container, $router);

        return $router;
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
