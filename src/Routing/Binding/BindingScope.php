<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Pulsar\Api\Api;

/**
 * How a bound route parameter is constrained.
 *
 * Route model binding treats containment in the route path as a security
 * boundary: `/users/{user}/posts/{post}` asserts that the post is one of the
 * user's, and the framework has to check that assertion before the handler
 * runs. This enum is the one place an application can say something other than
 * what the path says, and it is deliberately a closed type — a scope cannot be
 * misspelled into the permissive branch.
 *
 * The three cases split into a default and two declarations:
 *
 * - {@see self::Path} is the default and is never a statement. The scope is
 *   read off the route path by {@see BindingResolver}, which decides between
 *   the two cases below and never emits this one.
 * - {@see self::Contained} says the parameter resolves through a parent, using
 *   the relation recorded alongside it. It is the escape hatch for a path
 *   segment that cannot name the relation — `/users/{user}/blog-posts/{post}`,
 *   where no PHP property can be called `blog-posts`.
 * - {@see self::Root} says the parameter is deliberately global even though it
 *   sits inside a nested path. It is the only way to get an unscoped child, it
 *   has to be written down, and it shows up in review as the words it is.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
enum BindingScope: string
{
    /**
     * Let the route path decide. The default, and an input state only.
     */
    case Path = 'path';

    /**
     * Resolve through the parent named by the route path, using the recorded
     * relation. {@see BindingMeta::$parentRelation} must be set.
     */
    case Contained = 'contained';

    /**
     * Resolve globally, on the parameter's own key, whatever the path implies.
     *
     * A declaration with real consequences: nothing checks that the resource
     * belongs to whatever precedes it in the URL, so the handler — or the
     * authorization hook — has to.
     *
     * Mind the reach of where it is written. `Router::model()` registers by
     * parameter *name*, so declaring `{post}` a root there makes every route
     * with a `{post}` in it resolve the post globally, nested or not. A
     * {@see CompiledBindingMap} entry is keyed by route name and reaches only
     * that one route.
     */
    case Root = 'root';
}
