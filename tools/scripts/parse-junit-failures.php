<?php

declare(strict_types=1);

$path = $argv[1] ?? 'C:/Users/Lenny/AppData/Local/Temp/phpunit-junit.xml';
$xml = simplexml_load_file($path);
if ($xml === false) {
    fwrite(STDERR, "cannot load $path\n");
    exit(1);
}

$byClass = [];
$byType = [];
$detail = [];

$walk = function (SimpleXMLElement $suite) use (&$walk, &$byClass, &$byType, &$detail): void {
    foreach ($suite->testsuite as $child) {
        $walk($child);
    }
    foreach ($suite->testcase as $tc) {
        foreach (['failure', 'error'] as $kind) {
            if (!isset($tc->$kind)) {
                continue;
            }
            $cls = (string) $tc['class'];
            $name = (string) $tc['name'];
            $byClass[$cls] = ($byClass[$cls] ?? 0) + 1;
            $txt = trim((string) $tc->$kind);
            // First non-empty, meaningful line (skip the "ClassName::method" header PHPUnit prepends).
            $lines = array_values(array_filter(array_map('trim', explode("\n", $txt)), static fn(string $l): bool => $l !== ''));
            $msg = '';
            foreach ($lines as $l) {
                if (preg_match('/^[A-Za-z0-9_\\\\]+::[A-Za-z0-9_]+$/', $l) === 1) {
                    continue;
                }
                $msg = $l;
                break;
            }
            $type = '(other)';
            if (preg_match('/([A-Za-z0-9_]+(Exception|Error))/', $msg, $m) === 1) {
                $type = $m[1];
            } elseif (str_starts_with($msg, 'Failed asserting')) {
                $type = 'assertion';
            }
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $detail[] = $cls . '::' . $name . "\n      " . substr($msg, 0, 160);
            break;
        }
    }
};
$walk($xml);

arsort($byClass);
arsort($byType);

echo "=== BY TYPE ===\n";
foreach ($byType as $t => $n) {
    echo sprintf("%4d  %s\n", $n, $t);
}
echo "\n=== BY CLASS ===\n";
foreach ($byClass as $c => $n) {
    echo sprintf("%4d  %s\n", $n, $c);
}
echo "\nTOTAL failing classes: " . count($byClass) . "\n";

if (in_array('--detail', $argv, true)) {
    echo "\n=== DETAIL ===\n";
    sort($detail);
    foreach ($detail as $d) {
        echo $d . "\n";
    }
}
