<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Consent;

use Pulsar\Api\Internal;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;

/**
 * Renders an HTML consent banner for analytics tracking.
 *
 * The banner uses LocalStorage (not cookies) to remember the visitor's
 * choice on the client side. Server-side consent is recorded via
 * ConsentManagerInterface through the ConsentController endpoints.
 *
 * The banner is non-intrusive, using pui-* CSS classes from the Pulsar
 * UI design system, and appears fixed at the bottom of the viewport.
 */
#[Internal(reason: 'Consent banner rendering; injected by AnalyticsConsentMiddleware')]
final readonly class AnalyticsConsentBanner
{
    private const string STORAGE_KEY = 'plsr_analytics_consent';
    private const string CONSENT_GRANT_ENDPOINT = '/plsr/consent/grant';
    private const string CONSENT_REVOKE_ENDPOINT = '/plsr/consent/revoke';

    public function __construct(
        private string $privacyPolicyUrl = '/privacy',
    ) {}

    /**
     * Render the consent banner HTML with inline JavaScript.
     *
     * The JavaScript handles:
     * - Checking LocalStorage for existing consent state
     * - Sending grant/revoke requests to the server
     * - Persisting the choice in LocalStorage
     * - Setting the X-Analytics-Consent header on subsequent requests
     */
    public function render(): string
    {
        $policyUrl = htmlspecialchars($this->privacyPolicyUrl, ENT_QUOTES, 'UTF-8');
        $storageKey = self::STORAGE_KEY;
        $grantEndpoint = self::CONSENT_GRANT_ENDPOINT;
        $revokeEndpoint = self::CONSENT_REVOKE_ENDPOINT;

        return sprintf(
            <<<'HTML'
                <div id="plsr-consent-banner" class="pui-consent-banner" role="dialog" aria-label="Analytics consent" style="display:none;">
                  <div class="pui-consent-banner__inner">
                    <p class="pui-consent-banner__text">
                      We use privacy-preserving analytics to understand how visitors use this site.
                      No cookies are used, and your data is never shared with third parties.
                      <a href="%s" class="pui-consent-banner__link" rel="noopener">Privacy policy</a>
                    </p>
                    <div class="pui-consent-banner__actions">
                      <button type="button" id="plsr-consent-accept" class="pui-btn pui-btn--primary pui-btn--sm">Accept analytics</button>
                      <button type="button" id="plsr-consent-decline" class="pui-btn pui-btn--secondary pui-btn--sm">Decline</button>
                    </div>
                  </div>
                </div>
                <style>
                  .pui-consent-banner {
                    position: fixed;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    z-index: 9999;
                    background: var(--pui-color-surface, #fff);
                    border-top: 1px solid var(--pui-color-border, #e2e8f0);
                    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.08);
                    padding: 1rem;
                  }
                  .pui-consent-banner__inner {
                    max-width: 960px;
                    margin: 0 auto;
                    display: flex;
                    align-items: center;
                    gap: 1.5rem;
                    flex-wrap: wrap;
                  }
                  .pui-consent-banner__text {
                    flex: 1 1 auto;
                    margin: 0;
                    font-size: 0.875rem;
                    line-height: 1.5;
                    color: var(--pui-color-text, #1a202c);
                  }
                  .pui-consent-banner__link {
                    color: var(--pui-color-primary, #3182ce);
                    text-decoration: underline;
                  }
                  .pui-consent-banner__actions {
                    display: flex;
                    gap: 0.5rem;
                    flex-shrink: 0;
                  }
                </style>
                <script>
                (function() {
                  'use strict';
                  var STORAGE_KEY = '%s';
                  var GRANT_URL = '%s';
                  var REVOKE_URL = '%s';
                  var banner = document.getElementById('plsr-consent-banner');
                  if (!banner) return;

                  // Check existing consent state in LocalStorage
                  var stored = null;
                  try { stored = localStorage.getItem(STORAGE_KEY); } catch(e) {}

                  if (stored === 'granted' || stored === 'declined') {
                    // Consent already recorded, do not show banner
                    return;
                  }

                  // Show the banner
                  banner.style.display = 'block';

                  function sendConsent(url, state) {
                    // Get CSRF token from meta tag if available
                    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                    var headers = { 'Content-Type': 'application/json' };
                    if (csrfMeta) {
                      headers['X-CSRF-Token'] = csrfMeta.getAttribute('content') || '';
                    }
                    fetch(url, {
                      method: 'POST',
                      headers: headers,
                      credentials: 'same-origin'
                    }).catch(function() {});
                    try { localStorage.setItem(STORAGE_KEY, state); } catch(e) {}
                    banner.style.display = 'none';
                  }

                  var acceptBtn = document.getElementById('plsr-consent-accept');
                  var declineBtn = document.getElementById('plsr-consent-decline');

                  if (acceptBtn) {
                    acceptBtn.addEventListener('click', function() {
                      sendConsent(GRANT_URL, 'granted');
                    });
                  }
                  if (declineBtn) {
                    declineBtn.addEventListener('click', function() {
                      sendConsent(REVOKE_URL, 'declined');
                    });
                  }
                })();
                </script>
                HTML,
            $policyUrl,
            $storageKey,
            $grantEndpoint,
            $revokeEndpoint,
        );
    }
}
