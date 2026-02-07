<?php

declare(strict_types=1);

// Pretend to be in a Contracts namespace — adapter import is a violation.
namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\NullProvider;

/**
 * Test fixture: contract-namespace file importing from an Adapter/Provider namespace.
 * Used to verify that ArchitectureRulesTest detects adapter leaks into contracts.
 *
 * DO NOT AUTOLOAD — parsed by ImportAnalyzer only.
 */
final class FakeAdapterLeak
{
}
