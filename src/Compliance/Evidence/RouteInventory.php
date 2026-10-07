<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\Inspection;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\DataPathVerifier;
use Pulsar\Routing\Route;

use function array_map;
use function count;
use function implode;
use function is_string;
use function sprintf;

/**
 * The deployment's own classified routes, and whether they carry the middleware
 * their classification requires.
 *
 * {@see DataPathVerifier} has existed since 1.0.0 and was fed by nobody: every
 * caller in the tree handed it a hand-built array in a test. This supplies it
 * from the live router instead, which is what turns ISO 27001 A.8.3 and NIST
 * PR.AA from claims about the framework into statements about the deployment.
 *
 * A route declares its classification through the route attribute
 * `data_classification`, whose value is one of the tokens
 * {@see DataPathVerifier} knows: `pci`, `phi`, `pii`, `financial`, `sensitive`.
 *
 *     new Route(
 *         methods: [Method::POST],
 *         path: '/payments',
 *         handler: PaymentController::class,
 *         attributes: ['data_classification' => 'pci'],
 *         middleware: ['encryption', 'authentication', 'audit', 'csrf'],
 *     );
 *
 * A deployment that classifies NO route observes `present: false`, not `true`.
 * Zero classified routes means nothing was verified, and reporting "all
 * classified routes are covered" over an empty set is the vacuous pass that this
 * whole design exists to make inexpressible.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class RouteInventory
{
    /** Route attribute key carrying a route's data classification. */
    public const string CLASSIFICATION_ATTRIBUTE = 'data_classification';

    /**
     * @param list<Route> $routes The router's inventory, as the composition root read it
     */
    public function __construct(
        private array $routes,
    ) {}

    /**
     * The classified routes in {@see DataPathVerifier}'s input shape.
     *
     * @return list<array{path: string, classification: string, middleware: list<string>}>
     */
    #[NoDiscard]
    public function classifiedRoutes(): array
    {
        $classified = [];

        foreach ($this->routes as $route) {
            /** @var mixed $classification */
            $classification = $route->attributes[self::CLASSIFICATION_ATTRIBUTE] ?? null;

            if (!is_string($classification) || $classification === '') {
                continue;
            }

            $classified[] = [
                'path' => $route->path,
                'classification' => $classification,
                'middleware' => $route->middleware,
            ];
        }

        return $classified;
    }

    /**
     * Whether every classified route carries the middleware its classification
     * requires, judged by {@see DataPathVerifier} against the active profile.
     *
     * Graded {@see ObservationGrade::Resolved}: what is being read is the
     * middleware stack actually attached to the route the router will dispatch,
     * not a setting expressing an intention to attach one.
     */
    #[NoDiscard]
    public function observe(DataPathVerifier $verifier): Observation
    {
        $routes = $this->classifiedRoutes();

        if ($routes === []) {
            return Observation::inspected(
                ObservationId::ClassifiedRouteCoverage,
                Inspection::nothingToInspect(
                    'classified routes',
                    sprintf(
                        'None of the %d registered routes declares a data classification, so no '
                            . 'route-level control coverage could be verified. Tag the routes that '
                            . 'handle regulated data with the "%s" route attribute.',
                        count($this->routes),
                        self::CLASSIFICATION_ATTRIBUTE,
                    ),
                ),
                self::class,
            );
        }

        $uncovered = [];

        foreach ($verifier->verify($routes) as $result) {
            if ($result->status === CheckStatus::Fail) {
                $uncovered[] = $result->message;
            }
        }

        $paths = array_map(
            static fn(array $route): string => $route['path'],
            $routes,
        );

        return Observation::inspected(
            ObservationId::ClassifiedRouteCoverage,
            Inspection::coverage(
                'classified routes',
                $paths,
                $uncovered,
                $uncovered === []
                    ? sprintf(
                        'All %d classified routes carry the middleware their classification requires.',
                        count($routes),
                    )
                    : sprintf(
                        '%d of %d classified routes are missing required middleware: %s',
                        count($uncovered),
                        count($routes),
                        implode(' | ', $uncovered),
                    ),
            ),
            self::class,
        );
    }
}
