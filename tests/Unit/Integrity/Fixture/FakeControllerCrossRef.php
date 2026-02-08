<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Fixture;

// Cross-module controller reference — architecture violation.
use Pulsar\Extension\Studio\Server\Controller\ApiController;

/**
 * Test fixture: references a controller from a different module.
 * Used to verify that ArchitectureRulesTest detects cross-module controller imports.
 *
 * DO NOT AUTOLOAD — parsed by ImportAnalyzer only.
 */
final class FakeControllerCrossRef
{
}
