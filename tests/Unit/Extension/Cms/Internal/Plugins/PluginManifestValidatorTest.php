<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\Plugins;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Plugins\PluginManifestValidator;
use Pulsar\Extension\Cms\Plugins\PluginManifest;

use function count;

#[CoversClass(PluginManifestValidator::class)]
final class PluginManifestValidatorTest extends TestCase
{
    private PluginManifestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PluginManifestValidator();
    }

    #[Test]
    public function validateAcceptsValidManifest(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
            description: 'A test plugin',
            authorName: 'Test Author',
            license: 'MIT',
            entryPoint: 'MyPlugin\\Plugin',
            capabilities: ['content_types', 'hooks'],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function validateRejectsEmptySlug(): void
    {
        $manifest = new PluginManifest(slug: '', displayName: 'Test', version: '1.0.0');

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('slug', $result->errors[0]);
    }

    #[Test]
    public function validateRejectsSlugOver200Chars(): void
    {
        $manifest = new PluginManifest(slug: str_repeat('a', 201), displayName: 'Test', version: '1.0.0');

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('200 characters', $result->errors[0]);
    }

    #[Test]
    #[DataProvider('invalidSlugProvider')]
    public function validateRejectsInvalidSlugFormat(string $slug): void
    {
        $manifest = new PluginManifest(slug: $slug, displayName: 'Test', version: '1.0.0');

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('lowercase alphanumeric', $result->errors[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugProvider(): iterable
    {
        yield 'uppercase' => ['My-Plugin'];
        yield 'leading hyphen' => ['-plugin'];
        yield 'trailing hyphen' => ['plugin-'];
        yield 'spaces' => ['my plugin'];
        yield 'special chars' => ['my_plugin!'];
    }

    #[Test]
    public function validateRejectsEmptyDisplayName(): void
    {
        $manifest = new PluginManifest(slug: 'test', displayName: '', version: '1.0.0');

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('display_name', $result->errors[0]);
    }

    #[Test]
    public function validateRejectsEmptyVersion(): void
    {
        $manifest = new PluginManifest(slug: 'test', displayName: 'Test', version: '');

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('version', $result->errors[0]);
    }

    #[Test]
    public function validateRejectsZeroVersion(): void
    {
        $manifest = new PluginManifest(slug: 'test', displayName: 'Test', version: '0.0.0');

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function validateRejectsInvalidSemVer(): void
    {
        $manifest = new PluginManifest(slug: 'test', displayName: 'Test', version: '1.0');

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('SemVer', $result->errors[0]);
    }

    #[Test]
    public function validateAcceptsSemVerWithPrerelease(): void
    {
        $manifest = new PluginManifest(
            slug: 'test',
            displayName: 'Test',
            version: '1.2.3-beta.1',
            description: 'desc',
            authorName: 'Author',
            license: 'MIT',
            entryPoint: 'Entry',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validateAcceptsSemVerWithBuildMetadata(): void
    {
        $manifest = new PluginManifest(
            slug: 'test',
            displayName: 'Test',
            version: '1.0.0+build.123',
            description: 'desc',
            authorName: 'Author',
            license: 'MIT',
            entryPoint: 'Entry',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validateRejectsUnknownCapabilities(): void
    {
        $manifest = new PluginManifest(
            slug: 'test',
            displayName: 'Test',
            version: '1.0.0',
            capabilities: ['unknown_capability'],
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('Unknown capability', $result->errors[0]);
    }

    #[Test]
    public function validateAcceptsValidCapabilities(): void
    {
        $manifest = new PluginManifest(
            slug: 'test',
            displayName: 'Test',
            version: '1.0.0',
            description: 'desc',
            authorName: 'Author',
            license: 'MIT',
            entryPoint: 'Entry',
            capabilities: ['content_types', 'admin_pages', 'hooks', 'shortcodes', 'block_types'],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validateRejectsInvalidDependencyConstraint(): void
    {
        $manifest = new PluginManifest(
            slug: 'test',
            displayName: 'Test',
            version: '1.0.0',
            dependencies: ['other-plugin' => 'invalid constraint!'],
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('Invalid version constraint', $result->errors[0]);
        self::assertStringContainsString('other-plugin', $result->errors[0]);
    }

    #[Test]
    public function validateAcceptsValidDependencyConstraints(): void
    {
        $manifest = new PluginManifest(
            slug: 'test',
            displayName: 'Test',
            version: '1.0.0',
            description: 'desc',
            authorName: 'Author',
            license: 'MIT',
            entryPoint: 'Entry',
            dependencies: [
                'dep-a' => '^1.0.0',
                'dep-b' => '~2.3',
                'dep-c' => '>=1.0.0',
            ],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validateWarnsOnMissingOptionalFields(): void
    {
        $manifest = new PluginManifest(
            slug: 'test',
            displayName: 'Test',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid === false && $result->warnings === []);
        self::assertNotEmpty($result->warnings);
        $warningText = implode(' | ', $result->warnings);
        self::assertStringContainsString('description', $warningText);
        self::assertStringContainsString('author_name', $warningText);
        self::assertStringContainsString('license', $warningText);
        self::assertStringContainsString('entry_point', $warningText);
    }

    #[Test]
    public function validateNoWarningsWhenAllOptionalFieldsPresent(): void
    {
        $manifest = new PluginManifest(
            slug: 'test',
            displayName: 'Test',
            version: '1.0.0',
            description: 'A description',
            authorName: 'Author',
            license: 'MIT',
            entryPoint: 'MyPlugin\\Main',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function validateCollectsMultipleErrors(): void
    {
        $manifest = new PluginManifest(
            slug: '',
            displayName: '',
            version: '',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertGreaterThanOrEqual(3, count($result->errors));
    }
}
