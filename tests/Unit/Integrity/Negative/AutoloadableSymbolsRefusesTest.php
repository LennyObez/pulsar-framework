<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\AutoloadableSymbolScanner;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;

use function implode;

/**
 * The autoloadability rule, watched refusing.
 *
 * The fault it exists for is invisible to a sequential run by construction: PHPUnit loads
 * every test file during discovery, so a class hiding in a file that does not bear its
 * name resolves anyway and the suite is green. It only dies under a parallel worker,
 * which loads the files it was assigned and nothing else — that is how six shared Forum
 * doubles took out four unrelated E2E classes, and how 66 tests broke at once.
 *
 * A rule against a fault that only appears under a different runner is exactly the rule
 * nobody watches fail. So the tree below contains the fault, in each shape it takes, and
 * each shape the rule must ignore.
 */
#[CoversClass(AutoloadableSymbolScanner::class)]
#[GuardsGate(
    gate: 'AutoloadableSymbolsTest::everySymbolReferencedAcrossFilesIsAutoloadable',
    plants: 'a second class hidden in a file named after the first, named by a sibling through an import and through the shared namespace',
)]
final class AutoloadableSymbolsRefusesTest extends FilesystemTestCase
{
    use PlantsFiles;

    #[Test]
    public function itRefusesAHiddenClassAnImportNames(): void
    {
        // The defect: two classes, one file. `Stray` is unloadable on its own.
        $this->plant($this->tempDirectory, 'src/Forum/Doubles.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Forum;

            final class Doubles
            {
            }

            final class Stray
            {
            }
            PHP);

        // A file in another namespace that imports it — the shape that dies under a
        // parallel worker with "Class not found".
        $this->plant($this->tempDirectory, 'tests/E2E/ThreadTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Tests\E2E;

            use Pulsar\Forum\Stray;

            final class ThreadTest
            {
                public function subject(): Stray
                {
                    return new Stray();
                }
            }
            PHP);

        $violations = $this->scanner()->violations();

        self::assertStringContainsString(
            'Stray',
            implode(' ', $violations),
            'The autoloadability rule stayed silent on a class hidden in a file named after '
            . 'another. What ships when it stays silent is a suite that is green '
            . 'sequentially and dies under paratest: the worker loads only the files it was '
            . 'assigned, the hidden class is never reached, and unrelated tests fail with '
            . '"Class not found" — 66 of them, the last time this happened. It reported: ['
            . implode(', ', $violations) . ']',
        );
    }

    /**
     * The other way a hidden class is reached: no import, same namespace.
     */
    #[Test]
    public function itRefusesAHiddenClassASiblingReachesThroughTheSharedNamespace(): void
    {
        $this->plant($this->tempDirectory, 'src/Forum/Doubles.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Forum;

            final class Doubles
            {
            }

            final class Stray
            {
            }
            PHP);

        $this->plant($this->tempDirectory, 'src/Forum/Thread.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Forum;

            final class Thread
            {
                public function subject(): Stray
                {
                    return new Stray();
                }
            }
            PHP);

        $violations = $this->scanner()->violations();

        self::assertStringContainsString(
            'Stray',
            implode(' ', $violations),
            'The rule missed a hidden class reached without an import, which is the commoner '
            . 'of the two shapes: same namespace, bare name, resolves during a sequential '
            . 'run and only during a sequential run. It reported: ['
            . implode(', ', $violations) . ']',
        );
    }

    /**
     * The three shapes that must NOT be reported, or the rule fails on healthy code and
     * gets switched off. Each of these resolves under every runner.
     */
    #[Test]
    public function itIsSilentOnSymbolsThatCannotFailToLoad(): void
    {
        // A hidden class nothing outside its own file names: always resolves.
        $this->plant($this->tempDirectory, 'src/Forum/Local.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Forum;

            final class Local
            {
                public function helper(): PrivateHelper
                {
                    return new PrivateHelper();
                }
            }

            final class PrivateHelper
            {
            }
            PHP);

        // An anonymous class, and a ::class fetch — neither is a declaration.
        $this->plant($this->tempDirectory, 'src/Forum/Factory.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Forum;

            final class Factory
            {
                public function make(): object
                {
                    return new class {
                        public string $name = Local::class;
                    };
                }
            }
            PHP);

        // A same-spelled class in another namespace: not the same symbol.
        $this->plant($this->tempDirectory, 'src/Admin/PrivateHelper.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Admin;

            final class PrivateHelper
            {
            }
            PHP);

        $scanner = $this->scanner();

        self::assertNotSame([], $scanner->sources(), 'no sources scanned — the refusals above would be vacuous');
        self::assertSame(
            [],
            $scanner->violations(),
            'the rule fired on code that resolves under every runner — a rule that fails on '
            . 'the healthy case is a rule somebody deletes',
        );
    }

    /**
     * Vendored code is not ours to shape, and a rule that fails the build over an upstream
     * package earns being switched off.
     */
    #[Test]
    public function itIsSilentOnVendoredCode(): void
    {
        $this->plant($this->tempDirectory, 'extensions/thing/vendor/acme/lib/Bundle.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Acme\Lib;

            final class Bundle
            {
            }

            final class Hidden
            {
            }
            PHP);

        $this->plant($this->tempDirectory, 'extensions/thing/vendor/acme/lib/Client.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Acme\Lib;

            final class Client
            {
                public function hidden(): Hidden
                {
                    return new Hidden();
                }
            }
            PHP);

        // Something real, so "no sources" cannot be mistaken for "no violations".
        $this->plant($this->tempDirectory, 'src/Forum/Thread.php', "<?php\n\nnamespace Pulsar\\Forum;\n\nfinal class Thread\n{\n}\n");

        self::assertSame([], $this->scanner()->violations());
    }

    private function scanner(): AutoloadableSymbolScanner
    {
        return new AutoloadableSymbolScanner($this->tempDirectory);
    }
}
