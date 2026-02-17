<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Pipeline\PipelineAuditor;
use Pulsar\SupplyChain\Pipeline\RequiredToolsConfig;

use function file_get_contents;

#[CoversClass(PipelineAuditor::class)]
final class PipelineAuditorTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/fixtures';

    #[Test]
    public function compliantWorkflowPassesAudit(): void
    {
        $content = file_get_contents(self::FIXTURES . '/compliant.yml');
        self::assertIsString($content);

        $auditor = new PipelineAuditor();
        $result = $auditor->audit(['ci.yml' => $content]);

        self::assertTrue($result->isCompliant);
        self::assertSame([], $result->missingTools);
        self::assertSame([], $result->bypasses);
        self::assertSame([], $result->unpinnedActions);
    }

    #[Test]
    public function compliantWorkflowDetectsAllRequiredTools(): void
    {
        $content = file_get_contents(self::FIXTURES . '/compliant.yml');
        self::assertIsString($content);

        $auditor = new PipelineAuditor();
        $result = $auditor->audit(['ci.yml' => $content]);

        self::assertContains('phpstan', $result->presentTools);
        self::assertContains('psalm', $result->presentTools);
        self::assertContains('composer-audit', $result->presentTools);
        self::assertContains('deptrac', $result->presentTools);
    }

    #[Test]
    public function nonCompliantWorkflowFailsAudit(): void
    {
        $content = file_get_contents(self::FIXTURES . '/non-compliant.yml');
        self::assertIsString($content);

        $auditor = new PipelineAuditor();
        $result = $auditor->audit(['broken.yml' => $content]);

        self::assertFalse($result->isCompliant);
    }

    #[Test]
    public function nonCompliantWorkflowDetectsContinueOnError(): void
    {
        $content = file_get_contents(self::FIXTURES . '/non-compliant.yml');
        self::assertIsString($content);

        $auditor = new PipelineAuditor();
        $result = $auditor->audit(['broken.yml' => $content]);

        $continueOnErrorBypasses = array_filter(
            $result->bypasses,
            static fn(array $b): bool => str_contains($b['reason'], 'continue-on-error'),
        );

        self::assertNotEmpty($continueOnErrorBypasses);
    }

    #[Test]
    public function nonCompliantWorkflowDetectsNoVerify(): void
    {
        $content = file_get_contents(self::FIXTURES . '/non-compliant.yml');
        self::assertIsString($content);

        $auditor = new PipelineAuditor();
        $result = $auditor->audit(['broken.yml' => $content]);

        $noVerifyBypasses = array_filter(
            $result->bypasses,
            static fn(array $b): bool => str_contains($b['reason'], '--no-verify'),
        );

        self::assertNotEmpty($noVerifyBypasses);
    }

    #[Test]
    public function nonCompliantWorkflowDetectsUnpinnedActions(): void
    {
        $content = file_get_contents(self::FIXTURES . '/non-compliant.yml');
        self::assertIsString($content);

        $auditor = new PipelineAuditor();
        $result = $auditor->audit(['broken.yml' => $content]);

        self::assertNotEmpty($result->unpinnedActions);

        $actions = array_column($result->unpinnedActions, 'action');
        self::assertContains('actions/checkout@v4', $actions);
        self::assertContains('some-org/untrusted-action@main', $actions);
    }

    #[Test]
    public function missingToolsWorkflowReportsMissingTools(): void
    {
        $content = file_get_contents(self::FIXTURES . '/missing-tools.yml');
        self::assertIsString($content);

        $auditor = new PipelineAuditor();
        $result = $auditor->audit(['minimal.yml' => $content]);

        self::assertFalse($result->isCompliant);
        self::assertContains('phpstan', $result->missingTools);
        self::assertContains('psalm', $result->missingTools);
        self::assertContains('composer-audit', $result->missingTools);
        self::assertContains('deptrac', $result->missingTools);
    }

    #[Test]
    public function emptyWorkflowsReturnsCompliantWithNoTools(): void
    {
        $config = new RequiredToolsConfig(requiredTools: []);

        $auditor = new PipelineAuditor($config);
        $result = $auditor->audit([]);

        self::assertTrue($result->isCompliant);
        self::assertSame([], $result->presentTools);
    }

    #[Test]
    public function customRequiredToolsAreRespected(): void
    {
        $config = new RequiredToolsConfig(
            requiredTools: ['semgrep'],
            detectionPatterns: [
                'semgrep' => ['semgrep scan', 'semgrep ci'],
            ],
        );

        $auditor = new PipelineAuditor($config);

        // Workflow without semgrep
        $result = $auditor->audit(['ci.yml' => 'name: Test\non: push\njobs:\n  test:\n    steps:\n      - run: phpunit']);

        self::assertFalse($result->isCompliant);
        self::assertContains('semgrep', $result->missingTools);
    }

    #[Test]
    public function customRequiredToolDetectedWhenPresent(): void
    {
        $config = new RequiredToolsConfig(
            requiredTools: ['semgrep'],
            detectionPatterns: [
                'semgrep' => ['semgrep scan'],
            ],
        );

        $auditor = new PipelineAuditor($config);

        $result = $auditor->audit(['ci.yml' => "name: Test\njobs:\n  scan:\n    steps:\n      - run: semgrep scan"]);

        self::assertTrue($result->isCompliant);
        self::assertContains('semgrep', $result->presentTools);
    }

    #[Test]
    public function multipleWorkflowsAreCombined(): void
    {
        $workflow1 = "name: Quality\njobs:\n  qa:\n    steps:\n      - uses: actions/checkout@b4ffde65f46336ab88eb53be808477a3936bae11\n      - run: vendor/bin/phpstan analyse\n      - run: vendor/bin/psalm";
        $workflow2 = "name: Security\njobs:\n  sec:\n    steps:\n      - uses: actions/checkout@b4ffde65f46336ab88eb53be808477a3936bae11\n      - run: composer audit\n      - run: vendor/bin/deptrac analyse";

        $auditor = new PipelineAuditor();
        $result = $auditor->audit(['quality.yml' => $workflow1, 'security.yml' => $workflow2]);

        self::assertTrue($result->isCompliant);
        self::assertContains('phpstan', $result->presentTools);
        self::assertContains('psalm', $result->presentTools);
        self::assertContains('composer-audit', $result->presentTools);
        self::assertContains('deptrac', $result->presentTools);
    }

    #[Test]
    public function localCompositeActionsAreNotFlaggedAsUnpinned(): void
    {
        $workflow = "name: Test\njobs:\n  test:\n    steps:\n      - uses: ./.github/actions/setup";

        $config = new RequiredToolsConfig(requiredTools: []);

        $auditor = new PipelineAuditor($config);
        $result = $auditor->audit(['ci.yml' => $workflow]);

        self::assertSame([], $result->unpinnedActions);
    }

    #[Test]
    public function shaPinnedActionsAreNotFlaggedAsUnpinned(): void
    {
        $workflow = "name: Test\njobs:\n  test:\n    steps:\n      - uses: actions/checkout@b4ffde65f46336ab88eb53be808477a3936bae11";

        $config = new RequiredToolsConfig(requiredTools: []);

        $auditor = new PipelineAuditor($config);
        $result = $auditor->audit(['ci.yml' => $workflow]);

        self::assertSame([], $result->unpinnedActions);
    }

    #[Test]
    public function toolDeduplicationAcrossWorkflows(): void
    {
        $workflow1 = "name: A\njobs:\n  a:\n    steps:\n      - run: phpstan analyse";
        $workflow2 = "name: B\njobs:\n  b:\n    steps:\n      - run: phpstan analyse";

        $config = new RequiredToolsConfig(requiredTools: ['phpstan']);

        $auditor = new PipelineAuditor($config);
        $result = $auditor->audit(['a.yml' => $workflow1, 'b.yml' => $workflow2]);

        // phpstan should appear only once despite being in two workflows
        $phpstanCount = array_count_values($result->presentTools)['phpstan'] ?? 0;
        self::assertSame(1, $phpstanCount);
    }
}
