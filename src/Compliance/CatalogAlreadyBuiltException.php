<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use LogicException;
use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when a deferred control source is contributed after the catalog has
 * already been read.
 *
 * A deferred source is only ever executed once, at the catalog's first read. A
 * source handed over after that moment would be executed too — but everything
 * already assessed was assessed without it, so a report produced in between
 * would silently omit the controls it declares. Silently omitting a control is
 * the same defect as silently claiming one: nobody can tell from the artefact
 * that anything is missing.
 *
 * A LogicException: it is a registration-order defect in the composition root or
 * in an extension's boot hook, never a fact about the deployment.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class CatalogAlreadyBuiltException extends LogicException
{
    #[NoDiscard]
    public static function forLateContribution(): self
    {
        return new self(sprintf(
            'A control source was contributed to %s after the catalog had already been read. '
                . 'Deferred sources run once, at the first read; one handed over later would '
                . 'declare controls that every report produced up to that point silently omitted. '
                . 'Contribute during boot — extension boot hooks run before any report is asked '
                . 'for — or register the declarations directly with register().',
            ControlCatalog::class,
        ));
    }
}
