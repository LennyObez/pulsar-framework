<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionConfigRegistry;

use function file_put_contents;
use function rand;
use function sort;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ExtensionConfigRegistry::class)]
final class ExtensionConfigRegistryTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            unlink($file);
        }

        $this->written = [];
    }

    #[Test]
    public function anEmptyRegistryReportsNothingAndAnswersWithDefaults(): void
    {
        $registry = new ExtensionConfigRegistry();

        self::assertFalse($registry->has('auth'));
        self::assertSame([], $registry->section('auth'));
        self::assertSame([], $registry->sections());
    }

    #[Test]
    public function anInMemorySectionIsServedWithoutAFile(): void
    {
        $registry = new ExtensionConfigRegistry(sections: ['orm' => ['connection' => 'testing']]);

        self::assertTrue($registry->has('orm'));
        self::assertSame(['connection' => 'testing'], $registry->section('orm'));
    }

    #[Test]
    public function aFileBackedSectionIsReadOnFirstUse(): void
    {
        $file = $this->write("<?php return ['currency' => 'EUR'];");
        $registry = new ExtensionConfigRegistry(['payments' => $file]);

        self::assertTrue($registry->has('payments'));
        self::assertSame(['currency' => 'EUR'], $registry->section('payments'));
    }

    #[Test]
    public function anInMemorySectionWinsOverTheFileOfTheSameName(): void
    {
        $file = $this->write("<?php return ['from' => 'file'];");
        $registry = new ExtensionConfigRegistry(
            ['payments' => $file],
            ['payments' => ['from' => 'memory']],
        );

        self::assertSame(['from' => 'memory'], $registry->section('payments'));
    }

    #[Test]
    public function aFileThatReturnsNoArrayIsRejected(): void
    {
        $registry = new ExtensionConfigRegistry(['grpc' => $this->write('<?php return "nope";')]);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessageMatches('/must return an array/');
        $registry->section('grpc');
    }

    #[Test]
    public function sectionsListsBothSourcesWithoutRepeatingANameCarriedByEach(): void
    {
        $registry = new ExtensionConfigRegistry(
            ['payments' => $this->write('<?php return [];'), 'form' => $this->write('<?php return [];')],
            ['payments' => [], 'orm' => []],
        );

        $sections = $registry->sections();
        sort($sections);

        self::assertSame(['form', 'orm', 'payments'], $sections);
    }

    #[Test]
    public function restrictedToKeepsTheNamedSectionsAndDropsEveryOther(): void
    {
        $registry = new ExtensionConfigRegistry(
            [
                'ai_governance' => $this->write('<?php return [\'require_model_card\' => true];'),
                'payments' => $this->write('<?php return [\'api_key\' => \'sk_live_secret\'];'),
            ],
        );

        $narrowed = $registry->restrictedTo(['ai_governance']);

        self::assertSame(['ai_governance'], $narrowed->sections());
        self::assertSame(['require_model_card' => true], $narrowed->section('ai_governance'));
        self::assertFalse($narrowed->has('payments'));
        self::assertSame([], $narrowed->section('payments'));
    }

    #[Test]
    public function restrictedToKeepsAnAlreadyResolvedSectionAsWellAsAFileBackedOne(): void
    {
        // A host override arrives as a resolved section rather than a file, and
        // narrowing must not silently prefer one source over the other.
        $registry = new ExtensionConfigRegistry(
            ['payments' => $this->write('<?php return [];')],
            ['ai_governance' => ['require_model_card' => true], 'orm' => ['driver' => 'mysql']],
        );

        $narrowed = $registry->restrictedTo(['ai_governance']);

        self::assertSame(['ai_governance'], $narrowed->sections());
        self::assertSame(['require_model_card' => true], $narrowed->section('ai_governance'));
    }

    #[Test]
    public function restrictedToLeavesTheRegistryItNarrowedUntouched(): void
    {
        // The host keeps one registry for the whole application; narrowing is a
        // view handed to one extension, never a mutation of the shared object.
        $registry = new ExtensionConfigRegistry(sections: ['ai_governance' => [], 'payments' => []]);

        $_ = $registry->restrictedTo(['ai_governance']);

        self::assertTrue($registry->has('payments'));
    }

    #[Test]
    public function restrictedToNamingNothingYieldsARegistryThatAnswersNotConfigured(): void
    {
        // What an extension shipping no config/ directory receives. Every
        // consumer builds its config through a fromArray() that fills defaults,
        // so an empty registry is a valid answer rather than an error.
        $registry = new ExtensionConfigRegistry(sections: ['payments' => ['api_key' => 'sk_live_secret']]);

        $narrowed = $registry->restrictedTo([]);

        self::assertSame([], $narrowed->sections());
        self::assertFalse($narrowed->has('payments'));
        self::assertSame([], $narrowed->section('payments'));
    }

    private function write(string $body): string
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_cfgreg_' . rand(100000, 999999) . '.php';
        file_put_contents($file, $body);
        $this->written[] = $file;

        return $file;
    }
}
