<?php

declare(strict_types=1);

/**
 * Rebuilds the generated table in docs/adr/README.md from the records themselves.
 *
 * WHY THIS IS GENERATED. A hand-written index of seventy-odd decisions is a second
 * copy of a fact, and this repository has already paid for one: docs/compliance.md
 * carried a grid of about 550 cells, each a letter typed by a human and verified by
 * nobody, and it was wrong. ADR-0045 removed the same defect from the code, where a
 * control status had been written as a literal instead of computed. An index that
 * cannot disagree with the series is the only kind worth having.
 *
 * WHY IT HAS A --check MODE. Generating is not enough on its own: a generator
 * nobody runs leaves the same stale table behind. `--check` fails when the
 * committed table differs from what the records say, so drift blocks a merge
 * rather than accumulating. That is ADR-0060 applied to this file — a check never
 * observed to fail is indistinguishable from no check, and tests/Unit/Tooling/
 * AdrIndexGateTest.php plants a drifted table and watches this refuse it.
 */

const BEGIN_MARKER = '<!-- BEGIN GENERATED INDEX -->';
const END_MARKER = '<!-- END GENERATED INDEX -->';

$root = dirname(__DIR__, 2);
$check = false;

// --root exists so the gate can be pointed at a planted series that violates the
// rules on purpose and observed refusing it. Without it the only way to watch
// this fail would be to break the real docs/adr, and a gate nobody has seen fail
// is indistinguishable from no gate (ADR-0060).
foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--check') {
        $check = true;

        continue;
    }

    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, 7);
    }
}

$adrDir = $root . '/docs/adr';
$indexPath = $adrDir . '/README.md';

if (!is_dir($adrDir)) {
    fwrite(STDERR, "docs/adr is not a directory\n");

    exit(1);
}

/** @var list<array{number: string, title: string, status: string, file: string}> $records */
$records = [];
$problems = [];

foreach (scandir($adrDir) ?: [] as $entry) {
    if (!preg_match('/^(\d{4})-(.+)\.md$/', $entry, $name)) {
        continue;
    }

    // 0000 is the template and README is this file's own output.
    if ($name[1] === '0000') {
        continue;
    }

    $body = (string) file_get_contents($adrDir . '/' . $entry);

    $title = preg_match('/^#\s*ADR-\d+:\s*(.+)$/m', $body, $m) === 1
        ? trim($m[1])
        : ucfirst(str_replace('-', ' ', $name[2]));

    // The Status section is the first non-empty line after the heading. Records
    // qualify it in prose ("Accepted, with decision 1 superseded"), so the raw
    // line is kept and only normalised for grouping.
    // Two shapes are in use and both are legitimate. The template puts Status in
    // its own section; a run of records written in early 2026 puts it in a
    // metadata list at the top instead. Reading only one shape would report four
    // decided records as statusless, which is how this generator first failed.
    $status = '';

    if (preg_match('/^##\s*Status\s*$\R+(.+?)$/m', $body, $m) === 1) {
        $status = trim(str_replace('**', '', $m[1]));
    } elseif (preg_match('/^[-*]\s*\*\*Status\*\*\s*:\s*(.+?)$/m', $body, $m) === 1) {
        $status = trim($m[1]);
    }

    if ($status === '') {
        $problems[] = sprintf('%s declares no status', $entry);
        $status = '(none)';
    }

    if (str_contains($status, 'Proposed |')) {
        $problems[] = sprintf('%s still carries the unfilled template status line', $entry);
    }

    $records[] = [
        'number' => $name[1],
        'title' => $title,
        'status' => $status,
        'file' => $entry,
    ];
}

if ($records === []) {
    fwrite(STDERR, "No architecture decision records found in docs/adr\n");

    exit(1);
}

usort($records, static fn(array $a, array $b): int => $a['number'] <=> $b['number']);

// A gap or a duplicate in the numbering is worth refusing: two records once
// collided on 0057, and a series that skips a number invites the next author to
// reuse it.
$seen = [];

foreach ($records as $record) {
    $n = (int) $record['number'];

    if (isset($seen[$n])) {
        $problems[] = sprintf('ADR-%s is used twice: %s and %s', $record['number'], $seen[$n], $record['file']);
    }

    $seen[$n] = $record['file'];
}

$expected = 1;

foreach (array_keys($seen) as $n) {
    while ($expected < $n) {
        $problems[] = sprintf('ADR-%04d is missing from the series', $expected);
        ++$expected;
    }

    ++$expected;
}

// Every relative link inside the series has to resolve. A cross-reference is how
// one record hands a reader to the one that replaced it, and a broken one is
// invisible until somebody clicks: ten records linked to
// `0001-architecture-decision-records.md`, a filename that has never existed in
// this repository, and the series carried them for the whole RC phase. That is
// ADR-0060's shape in a directory of documents -- nothing failed, so nothing was
// found.
//
// Scanned over every .md in docs/adr, README and template included, because the
// index this script writes is itself full of links. Fenced code blocks are
// stripped first: a snippet may legitimately contain `](something)` that is not
// a link to anything. Absolute URLs and bare anchors are skipped -- resolving
// those is not this gate's job.
/** @var array<string, list<string>> $linkTargets */
$linkTargets = [];

foreach (scandir($adrDir) ?: [] as $entry) {
    if (!str_ends_with($entry, '.md')) {
        continue;
    }

    $prose = (string) preg_replace('/^```.*?^```/ms', '', (string) file_get_contents($adrDir . '/' . $entry));

    $matched = preg_match_all('/\]\(([^)\s]+)\)/', $prose, $links);

    if ($matched === false || $matched === 0) {
        continue;
    }

    foreach ($links[1] as $link) {
        if (preg_match('~^(https?:|mailto:|\#)~', $link) === 1) {
            continue;
        }

        $target = explode('#', $link)[0];

        if ($target === '') {
            continue;
        }

        $linkTargets[$entry][] = rawurldecode($target);

        if (file_exists($adrDir . '/' . rawurldecode($target))) {
            continue;
        }

        $problems[] = sprintf('%s links to %s, which does not exist', $entry, $link);
    }
}

// A record that hands a reader to a superseded decision has to hand them the
// successor in the same breath. Resolving is not enough, and the check above is
// therefore not enough: ADR-0057's Links section pointed at ADR-0005 for "why
// per-Fiber state is keyed the way it is", the link resolved perfectly, and the
// Fiber rule it resolved to had already been replaced by ADR-0071. The
// supersession is recorded on the record that was superseded -- which is the one
// place a reader arriving by link is not obliged to look, because they arrived
// mid-document at the section they were sent to.
//
// Three records were doing exactly that when this was written: 0034 and 0063 at
// ADR-0004, and 0057 at ADR-0005. None of them was broken in any way the link
// resolver could see, which is why the resolver had carried them since the
// supersessions landed.
//
// README.md is passed over because listing the closed records IS its job, and it
// prints the successor in the status column beside every one of them. So is the
// successor itself: ADR-0071 cites ADR-0005 to say what it replaced, and
// demanding it cite itself as well would be nonsense.
/** @var array<string, string> $successorOf */
$successorOf = [];

foreach ($records as $record) {
    if (preg_match('/^Superseded by \[[^\]]+\]\(([^)\s]+)\)/i', $record['status'], $m) === 1) {
        $successorOf[$record['file']] = rawurldecode(explode('#', $m[1])[0]);
    }
}

foreach ($linkTargets as $entry => $targets) {
    if ($entry === 'README.md') {
        continue;
    }

    foreach (array_unique($targets) as $target) {
        $successor = $successorOf[$target] ?? null;

        if ($successor === null || $entry === $target || $entry === $successor) {
            continue;
        }

        if (in_array($successor, $targets, true)) {
            continue;
        }

        $problems[] = sprintf(
            '%s links to %s without also naming %s, which superseded it',
            $entry,
            $target,
            $successor,
        );
    }
}

$inForce = [];
$closed = [];

foreach ($records as $record) {
    $isClosed = preg_match('/^(Superseded|Deprecated|Rejected|Withdrawn)/i', $record['status']) === 1;
    $isClosed ? $closed[] = $record : $inForce[] = $record;
}

$lines = [];
$lines[] = sprintf(
    '**%d records: %d in force, %d superseded or deprecated.**',
    count($records),
    count($inForce),
    count($closed),
);
$lines[] = '';
$lines[] = '### In force';
$lines[] = '';
$lines[] = '| # | Decision | Status |';
$lines[] = '| --- | --- | --- |';

foreach ($inForce as $record) {
    $lines[] = sprintf(
        '| %s | [%s](%s) | %s |',
        $record['number'],
        str_replace('|', '\\|', $record['title']),
        rawurlencode($record['file']),
        str_replace('|', '\\|', $record['status']),
    );
}

if ($closed !== []) {
    $lines[] = '';
    $lines[] = '### Superseded or deprecated';
    $lines[] = '';
    $lines[] = 'Kept deliberately. Read the successor for what is in force; read these';
    $lines[] = 'for why the earlier answer looked right at the time.';
    $lines[] = '';
    $lines[] = '| # | Decision | Status |';
    $lines[] = '| --- | --- | --- |';

    foreach ($closed as $record) {
        $lines[] = sprintf(
            '| %s | [%s](%s) | %s |',
            $record['number'],
            str_replace('|', '\\|', $record['title']),
            rawurlencode($record['file']),
            str_replace('|', '\\|', $record['status']),
        );
    }
}

$table = implode("\n", $lines);

$current = (string) file_get_contents($indexPath);

$pattern = '/' . preg_quote(BEGIN_MARKER, '/') . '.*?' . preg_quote(END_MARKER, '/') . '/s';

if (preg_match($pattern, $current) !== 1) {
    fwrite(STDERR, sprintf(
        "docs/adr/README.md is missing the %s / %s markers, so there is nowhere to write the index.\n",
        BEGIN_MARKER,
        END_MARKER,
    ));

    exit(1);
}

$rebuilt = (string) preg_replace(
    $pattern,
    BEGIN_MARKER . "\n\n" . $table . "\n\n" . END_MARKER,
    $current,
);

if ($problems !== []) {
    fwrite(STDERR, "The record series itself has problems:\n");

    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }

    fwrite(STDERR, "\nFix these in docs/adr; the index describes the series and cannot repair it.\n");

    exit(1);
}

if ($check) {
    if ($rebuilt === $current) {
        printf("docs/adr/README.md matches the %d records it indexes.\n", count($records));

        exit(0);
    }

    fwrite(STDERR, "docs/adr/README.md is out of date with the records. Run `composer adr:index`.\n");

    exit(1);
}

file_put_contents($indexPath, $rebuilt);

printf(
    "docs/adr/README.md rebuilt: %d records, %d in force, %d superseded or deprecated.\n",
    count($records),
    count($inForce),
    count($closed),
);
