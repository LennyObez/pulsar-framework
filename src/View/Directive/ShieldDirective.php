<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @shield directive to render the full anti-spam form shield.
 *
 * Emits two complementary, self-hosted defences into a form:
 *  - the managed-challenge widget (hidden token field + same-origin
 *    proof-of-work script), passing the request CSP nonce through so the script
 *    tag stays `script-src 'self'` compliant; and
 *  - the time-trap stamp (a server-rendered hidden field, no JavaScript) which
 *    covers clients with scripting disabled; and
 *  - the behavioural-signals collector (hidden field + same-origin script) when
 *    the score-only behavioural check is enabled.
 *
 * Each renderer degrades to an empty string when its provider is not
 * configured, so @shield is safe to place in any form regardless of which
 * anti-spam features an application has enabled.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class ShieldDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'shield';
    }

    public function compile(string $expression): string
    {
        return '<?php echo \Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeRenderer::renderGlobal($__csp_nonce ?? null)'
            . ' . \Pulsar\Security\AntiSpam\TimeTrap\TimeTrapRenderer::renderGlobal()'
            . ' . \Pulsar\Security\AntiSpam\Behavior\BehaviorCollectorRenderer::renderGlobal($__csp_nonce ?? null); ?>';
    }
}
