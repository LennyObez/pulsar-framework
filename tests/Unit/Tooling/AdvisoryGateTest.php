<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

use function file_put_contents;
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Guards the dependency audit gate, whose defect was an unreadable report summed to
 * zero and announced as a clean tree. Each case is a way it could pass a tree it did
 * not see clean.
 */
#[GuardsGate(gate: 'tools/ci/assert-no-advisories.php', plants: 'an advisory under zero totals, a muted advisory, a silencing auditConfig, a truncated report and a composer advisory')]
final class AdvisoryGateTest extends TestCase
{
    use InvokesCiScript;

    private const string SCRIPT = __DIR__ . '/../../../tools/ci/assert-no-advisories.php';

    private const array ZERO = ['info' => 0, 'low' => 0, 'moderate' => 0, 'high' => 0, 'critical' => 0];

    /** @var list<string> */
    private array $written = [];

    private string $workspace = '';

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/pulsar-advisory-' . uniqid();
        mkdir($this->workspace);
    }

    protected function tearDown(): void
    {
        foreach ([...$this->written, $this->workspace . '/pnpm-workspace.yaml'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->workspace)) {
            rmdir($this->workspace);
        }

        $this->written = [];
    }

    #[Test]
    public function itPassesAPnpm12ReportWithNoAdvisory(): void
    {
        [$status, $stdout] = $this->pnpm(['advisories' => [], 'metadata' => ['vulnerabilities' => self::ZERO]]);

        self::assertSame(0, $status, $stdout);
    }

    /** The list is read, not the totals: a summary can say zero while the list is not empty. */
    #[Test]
    public function itRefusesAnAdvisoryListedUnderZeroTotals(): void
    {
        [$status, $stdout] = $this->pnpm([
            'advisories' => ['1' => ['module_name' => 'brace-expansion', 'severity' => 'high', 'title' => 'ReDoS']],
            'metadata' => ['vulnerabilities' => self::ZERO],
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('HIGH: brace-expansion', $stdout);
    }

    #[Test]
    public function itRefusesAPositiveTotalUnderAnEmptyList(): void
    {
        [$status, $stdout] = $this->pnpm(['advisories' => [], 'metadata' => ['vulnerabilities' => ['high' => 1] + self::ZERO]]);

        self::assertSame(1, $status);
        self::assertStringContainsString('UNKNOWN: metadata.vulnerabilities totals 1', $stdout);
    }

    /** pnpm 10 and earlier still report what they silenced; that fails too. */
    #[Test]
    public function itRefusesAMutedAdvisory(): void
    {
        [$status, $stdout] = $this->pnpm([
            'advisories' => [],
            'muted' => [['module_name' => 'vite', 'title' => 'Path traversal']],
            'metadata' => ['vulnerabilities' => self::ZERO],
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('MUTED: vite', $stdout);
    }

    /** From pnpm 11, a silenced advisory leaves no trace in the report, so the setting itself is refused. */
    #[Test]
    public function itRefusesAWorkspaceThatSilencesAdvisories(): void
    {
        file_put_contents($this->workspace . '/pnpm-workspace.yaml', "auditConfig:\n  ignoreGhsas:\n    - GHSA-xxxx-xxxx-xxxx\n");

        [$status, , $stderr] = $this->pnpm(['advisories' => [], 'metadata' => ['vulnerabilities' => self::ZERO]]);

        self::assertSame(2, $status);
        self::assertStringContainsString('declares auditConfig', $stderr);
    }

    #[Test]
    public function itRefusesATruncatedReportRatherThanReadingItAsClean(): void
    {
        $report = $this->file('{"advisories": {}, "metadata": {"vulnerabilities": {"high": 0');

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--ecosystem=pnpm', '--workspace=' . $this->workspace, $report);

        self::assertSame(2, $status);
        self::assertStringContainsString('is not valid JSON', $stderr);
    }

    #[Test]
    public function itRefusesAComposerAdvisory(): void
    {
        $report = $this->file(json_encode(['advisories' => ['league/commonmark' => [
            ['cve' => 'CVE-2026-0001', 'title' => 'XSS through on* attributes', 'severity' => 'medium'],
        ]]], JSON_THROW_ON_ERROR));

        [$status, $stdout] = $this->runScript(self::SCRIPT, $report);

        self::assertSame(1, $status);
        self::assertStringContainsString('MEDIUM: league/commonmark — CVE-2026-0001', $stdout);
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function pnpm(array $report): array
    {
        $path = $this->file(json_encode($report, JSON_THROW_ON_ERROR));

        return $this->runScript(self::SCRIPT, '--ecosystem=pnpm', '--workspace=' . $this->workspace, $path);
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar-advisory-test-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }
}
