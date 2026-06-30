<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @timetrap directive to render the time-trap stamp field.
 *
 * Emits a server-rendered hidden field carrying a signed form-fill-timing stamp
 * (no JavaScript, no inline script — CSP `script-src 'self'` clean). Accepts an
 * optional form identifier expression — `@timetrap('contact')` — that binds the
 * stamp to one form so it cannot be replayed on another. Renders nothing when
 * the time-trap is not configured.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class TimeTrapDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'timetrap';
    }

    public function compile(string $expression): string
    {
        $expression = trim($expression);
        $argument = $expression === '' ? "''" : $expression;

        return sprintf(
            '<?php echo \Pulsar\Security\AntiSpam\TimeTrap\TimeTrapRenderer::renderGlobal(%s); ?>',
            $argument,
        );
    }
}
