<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Internal\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Contracts\ProcessResult;
use Pulsar\Extension\Studio\Contracts\ProcessRunnerInterface;
use Pulsar\Extension\Studio\Internal\Diagnostics\ProcessGitLogReader;

#[CoversClass(ProcessGitLogReader::class)]
final class ProcessGitLogReaderTest extends TestCase
{
    #[Test]
    public function getVersionTagsReturnsFilteredTags(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::success(
            "v1.0.0\nv0.9.0\nsome-branch\nv0.8.1\n",
        ));

        $reader = new ProcessGitLogReader($runner);
        $tags = $reader->getVersionTags();

        self::assertSame(['v1.0.0', 'v0.9.0', 'v0.8.1'], $tags);
    }

    #[Test]
    public function getVersionTagsRespectsLimit(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::success(
            "v3.0.0\nv2.0.0\nv1.0.0\n",
        ));

        $reader = new ProcessGitLogReader($runner);
        $tags = $reader->getVersionTags(limit: 2);

        self::assertCount(2, $tags);
        self::assertSame('v3.0.0', $tags[0]);
    }

    #[Test]
    public function getVersionTagsReturnsEmptyOnError(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::error('fatal: not a git repo'));

        $reader = new ProcessGitLogReader($runner);

        self::assertSame([], $reader->getVersionTags());
    }

    #[Test]
    public function getVersionTagsReturnsEmptyForEmptyOutput(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::success(''));

        self::assertSame([], new ProcessGitLogReader($runner)->getVersionTags());
    }

    #[Test]
    public function getVersionTagsSkipsEmptyLines(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::success(
            "\n\nv1.0.0\n\n",
        ));

        self::assertSame(['v1.0.0'], new ProcessGitLogReader($runner)->getVersionTags());
    }

    #[Test]
    public function getCurrentRefReturnsTag(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::success("v1.0.0-rc.10\n"));

        self::assertSame('v1.0.0-rc.10', new ProcessGitLogReader($runner)->getCurrentRef());
    }

    #[Test]
    public function getCurrentRefReturnsUnknownOnError(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::error());

        self::assertSame('unknown', new ProcessGitLogReader($runner)->getCurrentRef());
    }

    #[Test]
    public function getCommitsBetweenParsesOutput(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::success(
            "abc123def|abc123d|fix: resolve bug|Author Name|2026-03-28 10:00:00 +0000\n"
            . "def456ghi|def456g|feat: add feature|Other Author|2026-03-27 09:00:00 +0000\n",
        ));

        $reader = new ProcessGitLogReader($runner);
        $commits = $reader->getCommitsBetween('v0.9.0', 'v1.0.0');

        self::assertCount(2, $commits);
        self::assertSame('abc123def', $commits[0]['hash']);
        self::assertSame('abc123d', $commits[0]['short_hash']);
        self::assertSame('fix: resolve bug', $commits[0]['subject']);
        self::assertSame('Author Name', $commits[0]['author']);
    }

    #[Test]
    public function getCommitsBetweenSkipsInvalidLines(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::success(
            "incomplete|line\n"
            . "abc|def|subject|author|date\n",
        ));

        $reader = new ProcessGitLogReader($runner);
        $commits = $reader->getCommitsBetween(null, 'HEAD');

        self::assertCount(1, $commits);
    }

    #[Test]
    public function getCommitsBetweenReturnsEmptyOnError(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::error());

        self::assertSame([], new ProcessGitLogReader($runner)->getCommitsBetween('v0.1', 'v0.2'));
    }

    #[Test]
    public function getDiffStatsParsesOutput(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::success(
            ' 15 files changed, 342 insertions(+), 87 deletions(-)',
        ));

        $reader = new ProcessGitLogReader($runner);
        $stats = $reader->getDiffStats('v0.9.0', 'v1.0.0');

        self::assertSame(15, $stats['files_changed']);
        self::assertSame(342, $stats['insertions']);
        self::assertSame(87, $stats['deletions']);
    }

    #[Test]
    public function getDiffStatsReturnsZerosOnError(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::error());

        $stats = new ProcessGitLogReader($runner)->getDiffStats('a', 'b');

        self::assertSame(0, $stats['files_changed']);
        self::assertSame(0, $stats['insertions']);
        self::assertSame(0, $stats['deletions']);
    }

    #[Test]
    public function getDiffStatsHandlesPartialOutput(): void
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner->method('run')->willReturn(ProcessResult::success(
            ' 3 files changed, 10 insertions(+)',
        ));

        $stats = new ProcessGitLogReader($runner)->getDiffStats('a', 'b');

        self::assertSame(3, $stats['files_changed']);
        self::assertSame(10, $stats['insertions']);
        self::assertSame(0, $stats['deletions']);
    }
}
