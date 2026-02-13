<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @guest directive for unauthenticated user checks.
 *
 * Renders content only if the current user is NOT authenticated.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class GuestDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'guest';
    }

    public function compile(string $expression): string
    {
        $guard = trim($expression) !== '' ? trim($expression) : 'null';

        return sprintf('<?php if (!$__auth->check(%s)): ?>', $guard);
    }
}
