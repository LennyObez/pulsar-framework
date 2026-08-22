<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\ManifestScope;
use Pulsar\Integrity\ManifestScopeWalker;

use function bin2hex;
use function dirname;
use function file_put_contents;
use function is_dir;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sort;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The one definition of what a manifest covers.
 *
 * Discovery used to live in the builder and be re-derived, differently, by the
 * verifier. These tests fix the answer in one place so the two sides cannot
 * drift apart again.
 */
#[CoversClass(ManifestScope::class)]
final class ManifestScopeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_scope_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_discovers_files_at_every_depth_of_a_recursive_pattern(): void
    {
        $this->createFile('src/Kernel.php', '<?php');
        $this->createFile('src/Http/Router.php', '<?php');
        $this->createFile('src/Http/Middleware/Csrf.php', '<?php');

        $scope = new ManifestScope(['src/**/*.php'], []);
        $found = $this->walk($scope, $this->tempDir);
        sort($found);

        self::assertSame(
            ['src/Http/Middleware/Csrf.php', 'src/Http/Router.php', 'src/Kernel.php'],
            $found,
        );
    }

    /**
     * A file sitting directly under the include root, with no sibling directory
     * to nest it in, must still be covered. It is the shortest path an attacker
     * has into a tracked tree.
     */
    #[Test]
    public function a_file_directly_under_the_include_root_is_covered(): void
    {
        $scope = new ManifestScope(['src/**/*.php'], []);

        self::assertTrue($scope->covers('src/shell.php'));
        self::assertTrue($scope->covers('src/Http/shell.php'));
        self::assertTrue($scope->covers('src/A/B/C/shell.php'));
    }

    #[Test]
    public function files_the_include_patterns_do_not_name_are_out_of_scope(): void
    {
        $this->createFile('src/Kernel.php', '<?php');
        $this->createFile('src/Http/.gitkeep', '');
        $this->createFile('src/README.md', '# notes');

        $scope = new ManifestScope(['src/**/*.php'], []);

        self::assertSame(['src/Kernel.php'], $this->walk($scope, $this->tempDir));
        self::assertFalse($scope->covers('src/Http/.gitkeep'));
        self::assertFalse($scope->covers('src/README.md'));
    }

    #[Test]
    public function exclusions_win_over_inclusions(): void
    {
        $this->createFile('src/Kernel.php', '<?php');
        $this->createFile('src/Generated/Proxy.php', '<?php');

        $scope = new ManifestScope(['src/**/*.php'], ['src/Generated/**']);

        self::assertSame(['src/Kernel.php'], $this->walk($scope, $this->tempDir));
        self::assertFalse($scope->covers('src/Generated/Proxy.php'));
    }

    #[Test]
    public function a_single_level_pattern_does_not_descend(): void
    {
        $this->createFile('bin/pulsar', '#!/usr/bin/env php');
        $this->createFile('bin/tools/helper', '#!/usr/bin/env php');

        $scope = new ManifestScope(['bin/*'], []);

        self::assertSame(['bin/pulsar'], $this->walk($scope, $this->tempDir));
    }

    #[Test]
    public function several_include_patterns_are_unioned(): void
    {
        $this->createFile('src/Kernel.php', '<?php');
        $this->createFile('config/app.php', '<?php');
        $this->createFile('bin/pulsar', '#!/usr/bin/env php');

        $scope = new ManifestScope(['src/**/*.php', 'config/**/*.php', 'bin/*'], []);
        $found = $this->walk($scope, $this->tempDir);
        sort($found);

        self::assertSame(['bin/pulsar', 'config/app.php', 'src/Kernel.php'], $found);
    }

    #[Test]
    public function an_include_root_that_does_not_exist_is_skipped(): void
    {
        $this->createFile('src/Kernel.php', '<?php');

        $scope = new ManifestScope(['src/**/*.php', 'extensions/**/*.php'], []);

        self::assertSame(['src/Kernel.php'], $this->walk($scope, $this->tempDir));
    }

    /**
     * Discovery reports forward slashes whatever the platform separator is, so
     * a manifest built on Windows verifies on Linux and the other way round.
     */
    #[Test]
    public function discovered_paths_always_use_forward_slashes(): void
    {
        $this->createFile('src/Http/Middleware/Csrf.php', '<?php');

        $scope = new ManifestScope(['src/**/*.php'], []);
        $found = $this->walk($scope, str_replace('/', DIRECTORY_SEPARATOR, $this->tempDir));

        self::assertSame(['src/Http/Middleware/Csrf.php'], $found);
    }

    #[Test]
    public function an_empty_scope_covers_nothing(): void
    {
        $this->createFile('src/Kernel.php', '<?php');

        $scope = new ManifestScope([], []);

        self::assertSame([], $this->walk($scope, $this->tempDir));
        self::assertFalse($scope->covers('src/Kernel.php'));
    }

    /**
     * The walk moved out of ManifestScope, but what these cases assert did not:
     * which files a set of patterns covers on a real tree. They exercise it
     * through the walker now.
     *
     * @return list<string>
     */
    private function walk(ManifestScope $scope, string $basePath): array
    {
        return new ManifestScopeWalker()->discover($scope, $basePath);
    }

    private function createFile(string $relativePath, string $content): void
    {
        $absolutePath = $this->tempDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($absolutePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        file_put_contents($absolutePath, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
