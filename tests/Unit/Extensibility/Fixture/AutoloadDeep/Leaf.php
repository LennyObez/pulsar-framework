<?php

declare(strict_types=1);

namespace PulsarAutoloadFixture\Deep;

/**
 * Autoload fixture mapped to its OWN directory under a deeper prefix than its
 * parent namespace, to verify longest-prefix-first resolution (mirrors the
 * OpenTelemetry / observability-export split).
 */
final class Leaf {}
