<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @shield directive to render the managed-challenge widget.
 *
 * Emits the self-hosted, privacy-preserving CAPTCHA widget (hidden token field
 * + same-origin proof-of-work script) into a form. Passes the request CSP
 * nonce through so the script tag stays `script-src 'self'` compliant. Renders
 * nothing when the 'managed' captcha provider is not configured.
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
        return '<?php echo \Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeRenderer::renderGlobal($__csp_nonce ?? null); ?>';
    }
}
