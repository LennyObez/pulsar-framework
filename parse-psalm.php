<?php
$data = json_decode(file_get_contents('C:/Users/Lenny/AppData/Local/Temp/psalm-output.json'), true);
echo 'Total errors: ' . count($data) . PHP_EOL;
$nonCms = [];
foreach ($data as $e) {
    $path = str_replace('\\', '/', $e['file_path'] ?? '');
    if (strpos($path, 'extensions/cms/src/') === false) {
        $nonCms[] = $e;
    }
}
echo 'Non-CMS errors: ' . count($nonCms) . PHP_EOL . PHP_EOL;

$byFile = [];
foreach ($nonCms as $e) {
    $short = str_replace('\\', '/', $e['file_path']);
    $byFile[$short][] = $e;
}
ksort($byFile);
foreach ($byFile as $file => $errors) {
    echo $file . ' (' . count($errors) . ')' . PHP_EOL;
    foreach ($errors as $e) {
        echo '  L' . $e['line_from'] . ': ' . $e['type'] . ' - ' . substr($e['message'], 0, 120) . PHP_EOL;
    }
    echo PHP_EOL;
}
