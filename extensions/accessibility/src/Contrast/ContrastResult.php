<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Contrast;

use Pulsar\Api\Api;

/**
 * Result of a contrast ratio check between a foreground and background color pair.
 */
#[Api(since: '1.0.0')]
final readonly class ContrastResult
{
    public bool $passesAaNormal;
    public bool $passesAaLarge;
    public bool $passesAaaNormal;
    public bool $passesAaaLarge;

    public function __construct(
        public ParsedColor $foreground,
        public ParsedColor $background,
        public string $foregroundToken,
        public string $backgroundToken,
        public float $ratio,
    ) {
        $this->passesAaNormal = $ratio >= 4.5;
        $this->passesAaLarge = $ratio >= 3.0;
        $this->passesAaaNormal = $ratio >= 7.0;
        $this->passesAaaLarge = $ratio >= 4.5;
    }
}
