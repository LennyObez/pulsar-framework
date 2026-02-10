<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Themes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Themes\ThemeManifestValidator;
use Pulsar\Extension\Cms\Themes\ThemeManifest;

use function count;

#[CoversClass(ThemeManifestValidator::class)]
final class ThemeManifestValidatorTest extends TestCase
{
    private ThemeManifestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ThemeManifestValidator();
    }

    // -- Valid manifests -------------------------------------------------------

    #[Test]
    public function validManifestPasses(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme',
            displayName: 'My Theme',
            version: '1.0.0',
            description: 'A test theme',
            authorName: 'Test Author',
            license: 'MIT',
            regions: ['header', 'content', 'footer'],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function validManifestWithPrereleaseVersion(): void
    {
        $manifest = new ThemeManifest(
            slug: 'alpha-theme',
            displayName: 'Alpha Theme',
            version: '1.2.3-beta.1',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validManifestWithBuildMetadata(): void
    {
        $manifest = new ThemeManifest(
            slug: 'meta-theme',
            displayName: 'Meta Theme',
            version: '2.0.0+build.123',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }

    // -- Missing required fields ----------------------------------------------

    #[Test]
    public function emptySlugFails(): void
    {
        $manifest = new ThemeManifest(
            slug: '',
            displayName: 'My Theme',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertNotEmpty($result->errors);
    }

    #[Test]
    public function emptyDisplayNameFails(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme',
            displayName: '',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function emptyVersionFails(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme',
            displayName: 'My Theme',
            version: '',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function defaultVersion000Fails(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme',
            displayName: 'My Theme',
            version: '0.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    // -- Invalid slug format --------------------------------------------------

    #[Test]
    public function slugWithUppercaseFails(): void
    {
        $manifest = new ThemeManifest(
            slug: 'My-Theme',
            displayName: 'My Theme',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function slugWithSpacesFails(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my theme',
            displayName: 'My Theme',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function slugStartingWithHyphenFails(): void
    {
        $manifest = new ThemeManifest(
            slug: '-my-theme',
            displayName: 'My Theme',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function slugEndingWithHyphenFails(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme-',
            displayName: 'My Theme',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function slugExceedingMaxLengthFails(): void
    {
        $manifest = new ThemeManifest(
            slug: str_repeat('a', 201),
            displayName: 'My Theme',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function slugAtMaxLengthPasses(): void
    {
        $manifest = new ThemeManifest(
            slug: str_repeat('a', 200),
            displayName: 'My Theme',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }

    // -- Invalid version format -----------------------------------------------

    #[Test]
    public function versionNotSemverFails(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme',
            displayName: 'My Theme',
            version: 'not-a-version',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function versionMissingPatchFails(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme',
            displayName: 'My Theme',
            version: '1.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    // -- Warnings for missing optional fields ---------------------------------

    #[Test]
    public function missingDescriptionProducesWarning(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme',
            displayName: 'My Theme',
            version: '1.0.0',
            description: null,
            authorName: 'Author',
            license: 'MIT',
            regions: ['header'],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
        self::assertNotEmpty($result->warnings);

        $joined = implode(' ', $result->warnings);
        self::assertStringContainsString('description', $joined);
    }

    #[Test]
    public function missingAuthorProducesWarning(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme',
            displayName: 'My Theme',
            version: '1.0.0',
            description: 'Desc',
            authorName: null,
            license: 'MIT',
            regions: ['header'],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);

        $joined = implode(' ', $result->warnings);
        self::assertStringContainsString('author', $joined);
    }

    #[Test]
    public function missingLicenseProducesWarning(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme',
            displayName: 'My Theme',
            version: '1.0.0',
            description: 'Desc',
            authorName: 'Author',
            license: null,
            regions: ['header'],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);

        $joined = implode(' ', $result->warnings);
        self::assertStringContainsString('license', $joined);
    }

    #[Test]
    public function noRegionsDeclaredProducesWarning(): void
    {
        $manifest = new ThemeManifest(
            slug: 'my-theme',
            displayName: 'My Theme',
            version: '1.0.0',
            description: 'Desc',
            authorName: 'Author',
            license: 'MIT',
            regions: [],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);

        $joined = implode(' ', $result->warnings);
        self::assertStringContainsString('region', $joined);
    }

    #[Test]
    public function fullyPopulatedManifestNoWarnings(): void
    {
        $manifest = new ThemeManifest(
            slug: 'complete-theme',
            displayName: 'Complete Theme',
            version: '1.0.0',
            description: 'A complete theme',
            authorName: 'Test Author',
            license: 'MIT',
            regions: ['header', 'content'],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
        self::assertSame([], $result->warnings);
    }

    // -- fromArray factory -----------------------------------------------------

    #[Test]
    public function manifestFromArrayValid(): void
    {
        $manifest = ThemeManifest::fromArray([
            'slug' => 'json-theme',
            'name' => 'JSON Theme',
            'version' => '2.1.0',
            'regions' => ['sidebar'],
        ]);

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
        self::assertSame('json-theme', $manifest->slug);
        self::assertSame('JSON Theme', $manifest->displayName);
    }

    #[Test]
    public function manifestFromArrayMissingFieldsFails(): void
    {
        $manifest = ThemeManifest::fromArray([]);

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertGreaterThanOrEqual(2, count($result->errors));
    }

    // -- Multiple errors accumulated ------------------------------------------

    #[Test]
    public function multipleErrorsAccumulated(): void
    {
        $manifest = new ThemeManifest(
            slug: '',
            displayName: '',
            version: '',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertGreaterThanOrEqual(3, count($result->errors));
    }
}
