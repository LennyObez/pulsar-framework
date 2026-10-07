<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\ConsentBanner;

use NoDiscard;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function implode;
use function json_encode;
use function sprintf;

use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Renders the cookie consent banner HTML and JavaScript.
 *
 * Generates a fully self-contained consent banner that:
 * - Shows consent categories with descriptions
 * - Persists consent to a cookie
 * - Blocks non-consented script tags (via type="text/plain" data-consent)
 * - Respects the granular opt-in setting for GDPR compliance
 *
 * The banner is client-side only: consent is recorded in a browser cookie.
 * Server-side consent tracking, if required, is handled separately by a
 * ConsentManagerInterface implementation reading that cookie.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConsentBannerRenderer
{
    public function __construct(
        private ConsentBannerConfig $config,
    ) {}

    /**
     * Render the consent banner HTML + inline JavaScript.
     *
     * Should be included just before </body> in the page layout.
     * The banner auto-hides if consent has already been recorded.
     */
    #[NoDiscard]
    public function render(): string
    {
        if (!$this->config->enabled) {
            return '';
        }

        $categories = $this->renderCategories();

        // Configuration travels to the same-origin consent-banner.js through
        // HTML data-* attributes, so nothing is embedded in an inline <script>
        // (which the default `script-src 'self'` policy would block anyway). The
        // categories array is JSON inside one attribute; htmlspecialchars keeps
        // it valid and XSS-safe once the tag is attribute-quoted, and the browser
        // hands getAttribute() back the decoded JSON for JSON.parse().
        $categoriesJson = htmlspecialchars(
            json_encode(
                array_map(
                    static fn(ConsentCategory $c): array => [
                        'key' => $c->key,
                        'required' => $c->required,
                        'default' => $c->defaultEnabled,
                    ],
                    $this->config->categories,
                ),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
            ENT_QUOTES,
            'UTF-8',
        );

        $cookieName = htmlspecialchars($this->config->cookieName, ENT_QUOTES, 'UTF-8');
        $cookieTtlDays = $this->config->cookieTtlDays;
        $granular = $this->config->granularOptIn ? '1' : '0';
        $position = htmlspecialchars($this->config->position, ENT_QUOTES, 'UTF-8');
        $privacyUrl = htmlspecialchars($this->config->privacyPolicyUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <div id="pulsar-consent-banner" class="pulsar-consent-banner pulsar-consent-{$position}" role="dialog" aria-label="Cookie consent" hidden data-cookie-name="{$cookieName}" data-cookie-ttl-days="{$cookieTtlDays}" data-granular="{$granular}" data-categories="{$categoriesJson}">
              <div class="pulsar-consent-inner">
                <p class="pulsar-consent-text">
                  We use cookies to enhance your experience.
                  <a href="{$privacyUrl}" class="pulsar-consent-link">Privacy Policy</a>
                </p>
                {$categories}
                <div class="pulsar-consent-actions">
                  <button type="button" id="pulsar-consent-accept" class="pulsar-btn pulsar-btn-primary">Accept All</button>
                  <button type="button" id="pulsar-consent-reject" class="pulsar-btn pulsar-btn-secondary">Reject Non-Essential</button>
                  <button type="button" id="pulsar-consent-save" class="pulsar-btn pulsar-btn-secondary" hidden>Save Preferences</button>
                </div>
              </div>
            </div>
            <script src="/ui/js/consent-banner.js" data-consent="necessary" defer></script>
            HTML;
    }

    /**
     * Render category checkboxes for granular opt-in.
     */
    private function renderCategories(): string
    {
        if (!$this->config->granularOptIn) {
            return '';
        }

        $lines = ['<div class="pulsar-consent-categories">'];

        foreach ($this->config->categories as $category) {
            $key = htmlspecialchars($category->key, ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars($category->label, ENT_QUOTES, 'UTF-8');
            $desc = htmlspecialchars($category->description, ENT_QUOTES, 'UTF-8');
            $checked = ($category->required || $category->defaultEnabled) ? ' checked' : '';
            $disabled = $category->required ? ' disabled' : '';

            $lines[] = sprintf(
                '<label class="pulsar-consent-category">'
                . '<input type="checkbox" id="consent-%s" name="consent[%s]"%s%s>'
                . '<span class="pulsar-consent-label">%s</span>'
                . '<span class="pulsar-consent-desc">%s</span>'
                . '</label>',
                $key,
                $key,
                $checked,
                $disabled,
                $label,
                $desc,
            );
        }

        $lines[] = '</div>';

        return implode("\n", $lines);
    }
}
