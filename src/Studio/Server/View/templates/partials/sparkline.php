<?php
/**
 * @var list<int|float>|null $data
 * @var int|null $width
 * @var int|null $height
 */
$values = array_map(static fn(mixed $v): float => (float) $v, $data ?? []);
$w = $width ?? 100;
$h = $height ?? 24;
?>
<svg class="sparkline" width="<?= $w ?>" height="<?= $h ?>" viewBox="0 0 <?= $w ?> <?= $h ?>">
    <?php
    $count = count($values);
if ($count > 1):
    assert($values !== []);
    $max = max($values) ?: 1.0;
    $min = min($values);
    $range = ($max - $min) ?: 1.0;
    $points = [];
    foreach ($values as $i => $v) {
        $x = (float) $i / (float) ($count - 1) * (float) $w;
        $y = (float) $h - ($v - $min) / $range * (float) ($h - 2) - 1.0;
        $points[] = (string) round($x, 1) . ',' . (string) round($y, 1);
    }
    ?>
        <polyline fill="none" stroke="#38bdf8" stroke-width="1.5" points="<?= implode(' ', $points) ?>" />
    <?php endif; ?>
</svg>
