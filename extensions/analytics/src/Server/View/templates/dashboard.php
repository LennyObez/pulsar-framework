<?php

declare(strict_types=1);
extract(['content' => (static function (): string {
    ob_start(); ?>
    <div class="analytics-dashboard">
        <header class="analytics-header">
            <div class="analytics-header__realtime">
                <span id="realtime-counter" class="realtime-counter">0</span>
                <span class="realtime-label" data-t="analytics.realtime.active_visitors"><?= __('analytics.realtime.active_visitors') ?></span>
            </div>
            <div id="date-picker" class="date-picker"></div>
        </header>

        <section id="metric-cards" class="metric-cards"></section>

        <section class="analytics-chart">
            <div id="timeseries-chart" class="timeseries-chart"></div>
        </section>

        <div class="analytics-breakdowns">
            <section class="breakdown-panel">
                <h3 data-t="analytics.dashboard.top_pages"><?= __('analytics.dashboard.top_pages') ?></h3>
                <div id="breakdown-pages" class="breakdown-table" data-dimension="page"></div>
            </section>
            <section class="breakdown-panel">
                <h3 data-t="analytics.dashboard.top_sources"><?= __('analytics.dashboard.top_sources') ?></h3>
                <div id="breakdown-referrers" class="breakdown-table" data-dimension="referrer"></div>
            </section>
            <section class="breakdown-panel">
                <h3 data-t="analytics.dashboard.top_countries"><?= __('analytics.dashboard.top_countries') ?></h3>
                <div id="breakdown-countries" class="breakdown-table" data-dimension="country"></div>
            </section>
            <section class="breakdown-panel">
                <h3 data-t="analytics.breakdown.device"><?= __('analytics.breakdown.device') ?></h3>
                <div id="breakdown-devices" class="breakdown-table" data-dimension="device"></div>
            </section>
            <section class="breakdown-panel">
                <h3 data-t="analytics.breakdown.browser"><?= __('analytics.breakdown.browser') ?></h3>
                <div id="breakdown-browsers" class="breakdown-table" data-dimension="browser"></div>
            </section>
            <section class="breakdown-panel">
                <h3 data-t="analytics.breakdown.os"><?= __('analytics.breakdown.os') ?></h3>
                <div id="breakdown-os" class="breakdown-table" data-dimension="os"></div>
            </section>
        </div>
    </div>
    <?php return ob_get_clean() ?: '';
})()]);
require __DIR__ . '/layout.php';
