<?php
/** @var string|null $dataJson */
$typedDataJson = $dataJson ?? '{}';
?>
<div id="app" data-page="request-explorer" data-payload='<?= htmlspecialchars($typedDataJson, ENT_QUOTES, 'UTF-8') ?>'></div>
