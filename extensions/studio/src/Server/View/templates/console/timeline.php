<?php
/** @var string|null $dataJson */
$typedDataJson = $dataJson ?? '{}';
?>
<div id="app" data-page="timeline" data-payload='<?= htmlspecialchars($typedDataJson, ENT_QUOTES, 'UTF-8') ?>'></div>
