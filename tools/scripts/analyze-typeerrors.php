<?php

declare(strict_types=1);

$xml = simplexml_load_file('C:/Users/Lenny/AppData/Local/Temp/phpunit-junit.xml');

if ($xml === false) {
    exit("failed to load junit\n");
}

$files = [];

$walk = function ($suite) use (&$walk, &$files): void {
    foreach ($suite->testsuite ?? [] as $sub) {
        $walk($sub);
    }
    foreach ($suite->testcase ?? [] as $tc) {
        if (!isset($tc->error)) {
            continue;
        }
        $type = (string) $tc->error['type'];
        if ($type !== 'TypeError') {
            continue;
        }
        $msg = (string) $tc->error;
        foreach (explode("\n", $msg) as $ln) {
            $ln = trim($ln);
            if (!preg_match('#^([A-Z]:[\\\\/][^:]+\.php):(\d+)$#', $ln, $m)) {
                continue;
            }
            $path = str_replace('\\', '/', $m[1]);
            if (str_contains($path, '/tests/')) {
                continue;
            }
            $files[$path] = ($files[$path] ?? 0) + 1;
            break;
        }
    }
};

$walk($xml);
arsort($files);

echo "files=" . count($files) . "\n";
$i = 0;
foreach ($files as $f => $n) {
    if ($i++ >= 50) {
        break;
    }
    printf("%4d  %s\n", $n, $f);
}
