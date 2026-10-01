<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Http;

use Pulsar\Http\Method;

use function count;
use function explode;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * One `<form>` element found in a shipped view, with the request it submits.
 *
 * `$segments` carries the target path split on `/`, so a route pattern can be
 * lined up against it segment by segment. A segment the template writes as a
 * literal ("bulk", "install") is distinguishable from one it interpolates
 * ({@see FormTargetSegment::$dynamic}), which is what tells a route written for
 * this URL apart from one that merely swallows the literal into a parameter.
 */
final readonly class DiscoveredForm
{
    /**
     * @param string $file Repository-relative path of the view holding the form
     * @param int $line 1-based line of the `<form` tag
     * @param Method $method The method the browser will use to submit
     * @param string $action The raw `action` attribute, template syntax intact
     * @param list<FormTargetSegment> $segments The target path, split on `/`
     */
    public function __construct(
        public string $file,
        public int $line,
        public Method $method,
        public string $action,
        public array $segments,
    ) {}

    /**
     * The target path with every interpolated segment replaced by a placeholder.
     */
    public function path(): string
    {
        $path = '';

        foreach ($this->segments as $segment) {
            $path .= '/' . ($segment->dynamic ? '1' : $segment->text);
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Whether this form submits to the admin UI rather than the public site.
     */
    public function isAdminTarget(): bool
    {
        return str_starts_with($this->path(), '/admin/');
    }

    /**
     * Line up a route path pattern against this form's target.
     *
     * A route serves the form only when it was written for this URL shape:
     * the same number of segments, every literal route segment equal to the
     * form's literal, and every parameterised route segment sitting where the
     * template interpolates a value. `POST /admin/cms/media/bulk` therefore does
     * NOT reach `/admin/cms/media/{id}` in this sense — the router will match it
     * and answer 405, but `{id}` is capturing the literal word "bulk", so the
     * route belongs to a different endpoint and the missing one is a missing
     * feature rather than a refused method.
     */
    public function isServedBy(string $routePath): bool
    {
        $routeSegments = explode('/', trim($routePath, '/'));
        $formSegments = $this->segments;

        if (count($routeSegments) !== count($formSegments)) {
            return false;
        }

        foreach ($routeSegments as $index => $routeSegment) {
            $formSegment = $formSegments[$index];

            if (str_contains($routeSegment, '{')) {
                if (!$formSegment->dynamic) {
                    return false;
                }

                continue;
            }

            if ($routeSegment !== $formSegment->text) {
                return false;
            }
        }

        return true;
    }
}
