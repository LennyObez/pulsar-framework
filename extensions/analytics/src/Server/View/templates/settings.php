<?php

declare(strict_types=1);

use Pulsar\Extension\Analytics\Config\AnalyticsConfig;

/**
 * @var AnalyticsConfig $config
 */
$boolLabel = static fn(bool $v): string => $v ? __('analytics.settings.yes') : __('analytics.settings.no');
$respectDnt = $boolLabel($config->privacy->respectDnt);
$anonymizeReferrer = $boolLabel($config->privacy->anonymizeReferrer);
extract(['content' => (static function () use ($config, $respectDnt, $anonymizeReferrer): string {
    ob_start(); ?>
    <div class="analytics-settings">
        <header class="analytics-header">
            <h2 data-t="analytics.settings.title"><?= __('analytics.settings.title') ?></h2>
        </header>

        <section class="settings-section">
            <h3 data-t="analytics.settings.tracking_code"><?= __('analytics.settings.tracking_code') ?></h3>
            <p data-t="analytics.settings.tracking_hint"><?= __('analytics.settings.tracking_hint') ?></p>
            <pre class="code-block"><code>&lt;script defer
      data-site="YOUR_TRACKING_ID"
      data-api="/plsr/api/event"
      src="/plsr/js/tracker.js"&gt;
    &lt;/script&gt;</code></pre>
        </section>

        <section class="settings-section">
            <h3 data-t="analytics.settings.current_config"><?= __('analytics.settings.current_config') ?></h3>
            <dl class="settings-list">
                <dt data-t="analytics.settings.collection_driver"><?= __('analytics.settings.collection_driver') ?></dt>
                <dd><?= htmlspecialchars($config->collectionDriver) ?></dd>
                <dt data-t="analytics.settings.respect_dnt"><?= __('analytics.settings.respect_dnt') ?></dt>
                <dd><?= htmlspecialchars($respectDnt) ?></dd>
                <dt data-t="analytics.settings.anonymize_referrer"><?= __('analytics.settings.anonymize_referrer') ?></dt>
                <dd><?= htmlspecialchars($anonymizeReferrer) ?></dd>
                <dt data-t="analytics.settings.raw_retention"><?= __('analytics.settings.raw_retention') ?></dt>
                <dd><?= __('analytics.settings.days', ['count' => $config->retention->rawDays]) ?></dd>
                <dt data-t="analytics.settings.aggregated_retention"><?= __('analytics.settings.aggregated_retention') ?></dt>
                <dd><?= __('analytics.settings.days', ['count' => $config->retention->aggregatedDays]) ?></dd>
                <dt data-t="analytics.settings.rate_limit"><?= __('analytics.settings.rate_limit') ?></dt>
                <dd><?= __('analytics.settings.events_per_min', ['count' => $config->rateLimit->maxEventsPerIpPerMinute]) ?></dd>
            </dl>
        </section>
    </div>
    <?php return ob_get_clean() ?: '';
})()]);
require __DIR__ . '/layout.php';
