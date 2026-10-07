<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Pipeline\PipelineAuditResult;

#[CoversClass(PipelineAuditResult::class)]
final class PipelineAuditResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $result = new PipelineAuditResult(
            presentTools: ['phpstan', 'psalm'],
            missingTools: ['deptrac'],
            bypasses: [['file' => 'ci.yml', 'step' => 'scan', 'reason' => 'continue-on-error']],
            unpinnedActions: [['file' => 'ci.yml', 'action' => 'actions/checkout@v4']],
            isCompliant: false,
        );

        self::assertSame(['phpstan', 'psalm'], $result->presentTools);
        self::assertSame(['deptrac'], $result->missingTools);
        self::assertCount(1, $result->bypasses);
        self::assertSame('ci.yml', $result->bypasses[0]['file']);
        self::assertCount(1, $result->unpinnedActions);
        self::assertSame('actions/checkout@v4', $result->unpinnedActions[0]['action']);
        self::assertFalse($result->isCompliant);
    }

    #[Test]
    public function compliantResultHasEmptyViolations(): void
    {
        $result = new PipelineAuditResult(
            presentTools: ['phpstan', 'psalm', 'composer-audit', 'deptrac'],
            missingTools: [],
            bypasses: [],
            unpinnedActions: [],
            isCompliant: true,
        );

        self::assertTrue($result->isCompliant);
        self::assertSame([], $result->missingTools);
        self::assertSame([], $result->bypasses);
        self::assertSame([], $result->unpinnedActions);
    }

    #[Test]
    public function emptyResultHasNoData(): void
    {
        $result = new PipelineAuditResult(
            presentTools: [],
            missingTools: [],
            bypasses: [],
            unpinnedActions: [],
            isCompliant: true,
        );

        self::assertSame([], $result->presentTools);
        self::assertTrue($result->isCompliant);
    }
}
