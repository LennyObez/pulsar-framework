<?php

declare(strict_types=1);
$content = <<<'HTML'
    <div class="analytics-dashboard">
        <header class="analytics-header">
            <div class="analytics-header__realtime">
                <span id="realtime-counter" class="realtime-counter">0</span>
                <span class="realtime-label">current visitors</span>
            </div>
            <div id="date-picker" class="date-picker"></div>
        </header>

        <section id="metric-cards" class="metric-cards"></section>

        <section class="analytics-chart">
            <div id="timeseries-chart" class="timeseries-chart"></div>
        </section>

        <div class="analytics-breakdowns">
            <section class="breakdown-panel">
                <h3>Top Pages</h3>
                <div id="breakdown-pages" class="breakdown-table" data-dimension="page"></div>
            </section>
            <section class="breakdown-panel">
                <h3>Top Sources</h3>
                <div id="breakdown-referrers" class="breakdown-table" data-dimension="referrer"></div>
            </section>
            <section class="breakdown-panel">
                <h3>Countries</h3>
                <div id="breakdown-countries" class="breakdown-table" data-dimension="country"></div>
            </section>
            <section class="breakdown-panel">
                <h3>Devices</h3>
                <div id="breakdown-devices" class="breakdown-table" data-dimension="device"></div>
            </section>
            <section class="breakdown-panel">
                <h3>Browsers</h3>
                <div id="breakdown-browsers" class="breakdown-table" data-dimension="browser"></div>
            </section>
            <section class="breakdown-panel">
                <h3>Operating Systems</h3>
                <div id="breakdown-os" class="breakdown-table" data-dimension="os"></div>
            </section>
        </div>
    </div>
    HTML;
require __DIR__ . '/layout.php';
