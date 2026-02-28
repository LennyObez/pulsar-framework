<?php

declare(strict_types=1);

use Pulsar\Extension\Analytics\Config\AnalyticsConfig;

/**
 * @var AnalyticsConfig $config
 * @var callable(bool): string $boolLabel
 */
$boolLabel = static fn(bool $v): string => $v ? 'Yes' : 'No';
$respectDnt = $boolLabel($config->privacy->respectDnt);
$anonymizeReferrer = $boolLabel($config->privacy->anonymizeReferrer);
extract(['content' => <<<HTML
    <div class="analytics-settings">
        <header class="analytics-header">
            <h2>Settings</h2>
        </header>

        <section class="settings-section">
            <h3>Tracking Script</h3>
            <p>Add this snippet to your website's <code>&lt;head&gt;</code> tag:</p>
            <pre class="code-block"><code>&lt;script defer
      data-site="YOUR_TRACKING_ID"
      data-api="/plsr/api/event"
      src="/plsr/js/tracker.js"&gt;
    &lt;/script&gt;</code></pre>
        </section>

        <section class="settings-section">
            <h3>Current Configuration</h3>
            <dl class="settings-list">
                <dt>Collection Driver</dt>
                <dd>$config->collectionDriver</dd>
                <dt>Respect DNT</dt>
                <dd>$respectDnt</dd>
                <dt>Anonymize Referrer</dt>
                <dd>$anonymizeReferrer</dd>
                <dt>Raw Data Retention</dt>
                <dd>{$config->retention->rawDays} days</dd>
                <dt>Aggregated Data Retention</dt>
                <dd>{$config->retention->aggregatedDays} days</dd>
                <dt>Rate Limit</dt>
                <dd>{$config->rateLimit->maxEventsPerIpPerMinute} events/min</dd>
            </dl>
        </section>
    </div>
    HTML]);
require __DIR__ . '/layout.php';
