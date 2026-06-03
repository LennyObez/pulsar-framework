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
use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;
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
        // Embedded directly in a <script> block (raw CDATA), not an HTML
        // attribute: use JSON hex-escaping so any value containing <, >, &, ',
        // or " produces valid, XSS-safe JavaScript. htmlspecialchars() is wrong
        // here — it would corrupt the JSON literal inside <script>.
        $configJson = json_encode([
            'cookieName' => $this->config->cookieName,
            'cookieTtlDays' => $this->config->cookieTtlDays,
            'granular' => $this->config->granularOptIn,
            'categories' => array_map(
                static fn(ConsentCategory $c): array => [
                    'key' => $c->key,
                    'required' => $c->required,
                    'default' => $c->defaultEnabled,
                ],
                $this->config->categories,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $position = htmlspecialchars($this->config->position, ENT_QUOTES, 'UTF-8');
        $privacyUrl = htmlspecialchars($this->config->privacyPolicyUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <div id="pulsar-consent-banner" class="pulsar-consent-banner pulsar-consent-{$position}" role="dialog" aria-label="Cookie consent" style="display:none">
              <div class="pulsar-consent-inner">
                <p class="pulsar-consent-text">
                  We use cookies to enhance your experience.
                  <a href="{$privacyUrl}" class="pulsar-consent-link">Privacy Policy</a>
                </p>
                {$categories}
                <div class="pulsar-consent-actions">
                  <button type="button" id="pulsar-consent-accept" class="pulsar-btn pulsar-btn-primary">Accept All</button>
                  <button type="button" id="pulsar-consent-reject" class="pulsar-btn pulsar-btn-secondary">Reject Non-Essential</button>
                  <button type="button" id="pulsar-consent-save" class="pulsar-btn pulsar-btn-secondary" style="display:none">Save Preferences</button>
                </div>
              </div>
            </div>
            <script data-consent="necessary">
            (function(){
              var cfg={$configJson};
              var banner=document.getElementById('pulsar-consent-banner');
              if(!banner)return;
              var existing=document.cookie.match(new RegExp('(?:^|;\\\\s*)'+cfg.cookieName+'=([^;]*)'));
              if(existing){return;}
              banner.style.display='';
              document.getElementById('pulsar-consent-accept').addEventListener('click',function(){
                saveConsent(cfg.categories.map(function(c){return c.key;}));
              });
              document.getElementById('pulsar-consent-reject').addEventListener('click',function(){
                saveConsent(cfg.categories.filter(function(c){return c.required;}).map(function(c){return c.key;}));
              });
              var saveBtn=document.getElementById('pulsar-consent-save');
              if(cfg.granular&&saveBtn){
                saveBtn.style.display='';
                saveBtn.addEventListener('click',function(){
                  var accepted=[];
                  cfg.categories.forEach(function(c){
                    var cb=document.getElementById('consent-'+c.key);
                    if(c.required||(cb&&cb.checked)){accepted.push(c.key);}
                  });
                  saveConsent(accepted);
                });
              }
              function saveConsent(categories){
                var val=categories.join(',');
                var d=new Date();d.setDate(d.getDate()+cfg.cookieTtlDays);
                document.cookie=cfg.cookieName+'='+val+';expires='+d.toUTCString()+';path=/;SameSite=Lax';
                banner.style.display='none';
              }
            })();
            </script>
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
