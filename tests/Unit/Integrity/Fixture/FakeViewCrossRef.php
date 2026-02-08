<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Fixture;

// Cross-module view reference — architecture violation.
use Pulsar\Extension\Studio\Server\View\ViewRenderer;

/**
 * Test fixture: references a view class from a different module.
 * Used to verify that ArchitectureRulesTest detects cross-module view imports.
 *
 * DO NOT AUTOLOAD — parsed by ImportAnalyzer only.
 */
final class FakeViewCrossRef
{
}
