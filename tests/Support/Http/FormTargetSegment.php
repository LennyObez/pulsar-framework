<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Http;

/**
 * One `/`-delimited segment of a form's target path.
 *
 * `$dynamic` records whether the template interpolated the segment — a
 * `{{ $item['id'] }}`, `<?= $e($id) ?>` or `{$id}` run — or wrote it out as a
 * literal. Only that distinction lets a route parameter be checked against the
 * thing it is supposed to capture.
 */
final readonly class FormTargetSegment
{
    public function __construct(
        public string $text,
        public bool $dynamic,
    ) {}
}
