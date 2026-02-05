<?php
/**
 * @var int|null $eventCount
 * @var string|null $storageSize
 * @var string|null $samplingRate
 */
$typedEventCount = $eventCount ?? 0;
$typedStorageSize = $storageSize ?? '0 B';
$typedSamplingRate = $samplingRate ?? '100%';
?>
<div class="container">
    <h1>Pulsar Studio</h1>
    <p class="subtitle">Observability and debugging dashboard</p>
    <div class="card">
        <h2>Quick Stats</h2>
        <p>Events recorded: <span class="stat"><?= $typedEventCount ?></span></p>
        <p>Storage used: <span class="stat"><?= htmlspecialchars($typedStorageSize, ENT_QUOTES, 'UTF-8') ?></span></p>
        <p>Sampling rate: <span class="stat"><?= htmlspecialchars($typedSamplingRate, ENT_QUOTES, 'UTF-8') ?></span></p>
    </div>
    <div class="card">
        <h2>Navigation</h2>
        <div class="links">
            <a href="/studio/console">Console Overview</a>
            <a href="/studio/console/requests">HTTP Requests</a>
            <a href="/studio/console/database">Database Queries</a>
            <a href="/studio/console/logs">Logs</a>
            <a href="/studio/console/exceptions">Exceptions</a>
        </div>
    </div>
</div>
