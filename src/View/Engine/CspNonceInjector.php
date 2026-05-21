<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use NoDiscard;
use Pulsar\Api\Internal;

use function preg_replace;

/**
 * Automatically injects CSP nonce attributes into script and style tags.
 *
 * This is a post-compilation pass that modifies the compiled template
 * output to add `nonce="<?php echo htmlspecialchars($__csp_nonce ?? '', ...); ?>"`
 * to all `<script>` and `<style>` tags that do not already have a nonce attribute.
 *
 * By performing this injection at compile-time rather than requiring manual
 * @csp_nonce directives, we ensure that every inline script and style tag
 * is automatically covered by the Content Security Policy.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'CSP injection is an engine implementation detail')]
final readonly class CspNonceInjector
{
    private const string NONCE_PHP = '<?php echo htmlspecialchars($__csp_nonce ?? \'\', ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); ?>';

    /**
     * Inject nonce attributes into script and style tags in compiled output.
     *
     * Skips tags that already contain a `nonce` attribute.
     * Skips tags with `src=` (external scripts) unless they are inline.
     *
     * @param string $compiled The compiled template PHP output
     *
     * @return string The compiled output with nonce attributes injected
     */
    #[NoDiscard]
    public static function inject(string $compiled): string
    {
        $nonce = self::NONCE_PHP;

        // Inject nonce into <script> tags that don't already have one
        $compiled = (string) preg_replace(
            '/<script(?![^>]*\bnonce\b)([^>]*)>/i',
            '<script nonce="' . $nonce . '"$1>',
            $compiled,
        );

        // Inject nonce into <style> tags that don't already have one
        $compiled = (string) preg_replace(
            '/<style(?![^>]*\bnonce\b)([^>]*)>/i',
            '<style nonce="' . $nonce . '"$1>',
            $compiled,
        );

        return $compiled;
    }
}
