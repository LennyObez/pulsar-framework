<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Fixture;

/**
 * Test fixture: uses a fully-qualified class reference without a use statement.
 * Used to verify that ImportAnalyzer catches inline FQ references via T_NAME_FULLY_QUALIFIED.
 *
 * DO NOT AUTOLOAD — parsed by ImportAnalyzer only.
 */
final class FakeFullyQualifiedRef
{
    public function example(): void
    {
        // Inline FQ reference — no use statement.
        $class = \Pulsar\Cache\FrameworkCache::class;
    }
}
