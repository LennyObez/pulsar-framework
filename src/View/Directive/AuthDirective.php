<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @auth directive for authenticated user checks.
 *
 * Renders content only if the current user is authenticated.
 * Optionally accepts a guard name.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class AuthDirective implements DirectiveInterface
{
    public function __construct(
        private string $directiveName = 'auth',
    ) {}

    public function name(): string
    {
        return $this->directiveName;
    }

    public function compile(string $expression): string
    {
        $guard = trim($expression) !== '' ? trim($expression) : 'null';

        return sprintf('<?php if ($__auth->check(%s)): ?>', $guard);
    }
}
