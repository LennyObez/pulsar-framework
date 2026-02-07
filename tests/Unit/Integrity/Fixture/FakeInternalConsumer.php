<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Fixture;

// These cross-module imports target #[Internal] classes — architecture violation.
use Pulsar\Cache\FrameworkCache;
use Pulsar\Observability\Log\Sink\DeferredSink;

/**
 * Test fixture: imports #[Internal] classes from other modules.
 * Used to verify that ArchitectureRulesTest detects cross-module internal imports.
 *
 * DO NOT AUTOLOAD — parsed by ImportAnalyzer only.
 */
final class FakeInternalConsumer
{
}
