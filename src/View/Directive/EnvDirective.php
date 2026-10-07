<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the `@env` directive for environment checks.
 *
 * Renders content only when the current environment matches.
 *
 * Usage: `@env('local') ... @endenv`
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class EnvDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'env';
    }

    public function compile(string $expression): string
    {
        $env = trim($expression);

        if ($env === '') {
            return '<?php if (false): ?>';
        }

        return sprintf('<?php if (($__env ?? null) === %s): ?>', $env);
    }
}
