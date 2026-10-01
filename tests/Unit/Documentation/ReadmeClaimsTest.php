<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control\ControlDeclaration;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

use function dirname;
use function file_exists;
use function file_get_contents;
use function implode;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function str_starts_with;
use function strtolower;

use const DIRECTORY_SEPARATOR;

/**
 * The README's first screen is the only page most readers will read.
 *
 * It opened with two shields and a status line, said nothing a build could
 * contradict, and pointed its CI badge at a workflow without naming a branch. It
 * now makes four checkable claims about this tree instead, and a claim about a
 * tree is worth exactly as much as the check that reads the tree back.
 *
 * These do not grade the prose. They fail when the repository stops matching what
 * the front page says about it — which is the failure that matters, because the
 * front page is where a stranger decides whether to trust the rest.
 */
#[CoversNothing]
final class ReadmeClaimsTest extends TestCase
{
    /**
     * Every relative link on the front page must resolve.
     *
     * The first screen now links source files and ADRs by path as evidence. A
     * dead one turns evidence into decoration.
     */
    #[Test]
    public function everyRelativeLinkInTheReadmeResolves(): void
    {
        $root = dirname(__DIR__, 3);
        preg_match_all('/\]\(([^)#\s]+)(?:#[^)\s]*)?\)/', $this->readme(), $matches);

        $missing = [];

        foreach ($matches[1] as $target) {
            if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
                continue;
            }

            if (!file_exists($root . DIRECTORY_SEPARATOR . $target)) {
                $missing[] = $target;
            }
        }

        self::assertSame([], $missing, 'README links to paths that do not exist: ' . implode(', ', $missing));
    }

    /**
     * The CI badge must name the branch it reports.
     *
     * Unqualified, the badge renders the workflow's latest run on the default
     * branch while reading, to anyone glancing at it, as the state of the code
     * they are looking at. Development happens on branches here, so the two were
     * months apart. Pinning it does not make it fresher; it makes it honest about
     * what it is measuring, and the README says the rest out loud.
     */
    #[Test]
    public function theCiBadgeNamesItsBranch(): void
    {
        if (preg_match('#actions/workflows/ci\.yml/badge\.svg\?branch=([a-zA-Z0-9._/-]+)#', $this->readme(), $matches) !== 1) {
            self::fail('The CI badge does not pin a branch, so it reports a run the reader cannot identify');
        }

        self::assertSame('main', $matches[1]);
    }

    /**
     * The claim: a compliance control cannot report itself satisfied.
     *
     * Every public entry point into a declaration is checked, not just the one
     * the README shows, because a status parameter added to any of them would
     * make the front page false.
     */
    #[Test]
    public function noControlDeclarationEntryPointAcceptsAStatus(): void
    {
        self::assertStringContainsString(
            'no status parameter',
            $this->readme(),
            'This test exists to back a README claim that is no longer made',
        );

        $reflection = new ReflectionClass(ControlDeclaration::class);
        $offenders = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                if (str_contains(strtolower($parameter->getName()), 'status')) {
                    $offenders[] = $method->getName() . '($' . $parameter->getName() . ')';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A control status can now be written as a literal: ' . implode(', ', $offenders),
        );
    }

    /**
     * The claim: `MonitoringHookInterface` has no implementation, so ISO 42001
     * Clause 9.1 cannot be satisfied today.
     *
     * The day someone writes one, this fails — and the README paragraph has to be
     * rewritten, which is the correct outcome. An absence stated on a front page
     * has to be re-checked, or it becomes a stale apology for a gap that closed.
     */
    #[Test]
    public function theMonitoringHookGapTheReadmeNamesIsStillOpen(): void
    {
        self::assertStringContainsString(
            'MonitoringHookInterface',
            $this->readme(),
            'This test exists to back a README claim that is no longer made',
        );

        $root = dirname(__DIR__, 3);
        $extension = $root . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . 'ai-governance';
        self::assertDirectoryExists($extension, 'The ai-governance extension the claim is about is gone');

        $implementors = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extension));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if ($source === false) {
                continue;
            }

            if (preg_match('/\bimplements\b[^{;]*\bMonitoringHookInterface\b/', $source) === 1) {
                $implementors[] = $file->getFilename();
            }
        }

        self::assertSame(
            [],
            $implementors,
            'MonitoringHookInterface now has an implementation, so the README gap paragraph is stale: '
            . implode(', ', $implementors),
        );
    }

    private function readme(): string
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'README.md';
        self::assertFileExists($path);

        $markdown = file_get_contents($path);
        self::assertIsString($markdown);

        return $markdown;
    }
}
