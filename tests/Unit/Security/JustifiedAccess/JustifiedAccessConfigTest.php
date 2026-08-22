<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\JustifiedAccess\JustificationCategory;
use Pulsar\Security\JustifiedAccess\JustifiedAccessConfig;

#[CoversClass(JustifiedAccessConfig::class)]
final class JustifiedAccessConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new JustifiedAccessConfig();

        self::assertTrue($config->enabled);
        self::assertCount(5, $config->defaultCategories);
        self::assertSame([], $config->requireSupervisorFor);
        self::assertSame(900, $config->breakTheGlassDuration);
        self::assertSame(50, $config->anomalyThreshold);
        self::assertSame(3600, $config->anomalyWindowSeconds);
        self::assertSame('X-Access-Justification', $config->justificationHeader);
        self::assertSame('X-Access-Justification-Category', $config->categoryHeader);
        self::assertSame(10, $config->minJustificationLength);
    }

    public function testFromArrayWithDefaults(): void
    {
        $config = JustifiedAccessConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertCount(5, $config->defaultCategories);
        self::assertSame(900, $config->breakTheGlassDuration);
    }

    public function testFromArrayWithCustomValues(): void
    {
        $config = JustifiedAccessConfig::fromArray([
            'enabled' => false,
            'default_categories' => ['emergency', 'audit'],
            'require_supervisor_for' => ['restricted', 'confidential'],
            'break_the_glass_duration' => 1800,
            'anomaly_threshold' => 100,
            'anomaly_window_seconds' => 7200,
            'justification_header' => 'X-Custom-Justification',
            'category_header' => 'X-Custom-Category',
            'min_justification_length' => 20,
        ]);

        self::assertFalse($config->enabled);
        self::assertCount(2, $config->defaultCategories);
        self::assertSame(JustificationCategory::Emergency, $config->defaultCategories[0]);
        self::assertSame(JustificationCategory::InternalAudit, $config->defaultCategories[1]);
        self::assertCount(2, $config->requireSupervisorFor);
        self::assertSame(DataClassification::Restricted, $config->requireSupervisorFor[0]);
        self::assertSame(DataClassification::Confidential, $config->requireSupervisorFor[1]);
        self::assertSame(1800, $config->breakTheGlassDuration);
        self::assertSame(100, $config->anomalyThreshold);
        self::assertSame(7200, $config->anomalyWindowSeconds);
        self::assertSame('X-Custom-Justification', $config->justificationHeader);
        self::assertSame('X-Custom-Category', $config->categoryHeader);
        self::assertSame(20, $config->minJustificationLength);
    }

    public function testFromArrayWithPartialValues(): void
    {
        $config = JustifiedAccessConfig::fromArray([
            'anomaly_threshold' => 75,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(75, $config->anomalyThreshold);
        self::assertSame(3600, $config->anomalyWindowSeconds);
    }

    public function testFromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = JustifiedAccessConfig::fromArray([
            'enabled' => 'yes',
            'break_the_glass_duration' => 'infinite',
            'anomaly_threshold' => '50',
            'justification_header' => 42,
            'min_justification_length' => false,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(900, $config->breakTheGlassDuration);
        self::assertSame(50, $config->anomalyThreshold);
        self::assertSame('X-Access-Justification', $config->justificationHeader);
        self::assertSame(10, $config->minJustificationLength);
    }

    public function testDefaultCategoriesContainExpected(): void
    {
        $config = new JustifiedAccessConfig();

        self::assertContains(JustificationCategory::CustomerRequest, $config->defaultCategories);
        self::assertContains(JustificationCategory::RegulatoryObligation, $config->defaultCategories);
        self::assertContains(JustificationCategory::InternalAudit, $config->defaultCategories);
        self::assertContains(JustificationCategory::DisputeResolution, $config->defaultCategories);
        self::assertContains(JustificationCategory::AccountMaintenance, $config->defaultCategories);
    }
}
