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
    public function test_valid_manifest_passes(): void
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
    public function test_valid_manifest_with_prerelease_version(): void
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
    public function test_valid_manifest_with_build_metadata(): void
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
    public function test_empty_slug_fails(): void
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
    public function test_empty_display_name_fails(): void
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
    public function test_empty_version_fails(): void
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
    public function test_default_version_0_0_0_fails(): void
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
    public function test_slug_with_uppercase_fails(): void
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
    public function test_slug_with_spaces_fails(): void
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
    public function test_slug_starting_with_hyphen_fails(): void
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
    public function test_slug_ending_with_hyphen_fails(): void
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
    public function test_slug_exceeding_max_length_fails(): void
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
    public function test_slug_at_max_length_passes(): void
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
    public function test_version_not_semver_fails(): void
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
    public function test_version_missing_patch_fails(): void
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
    public function test_missing_description_produces_warning(): void
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
    public function test_missing_author_produces_warning(): void
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
    public function test_missing_license_produces_warning(): void
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
    public function test_no_regions_declared_produces_warning(): void
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
    public function test_fully_populated_manifest_no_warnings(): void
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
    public function test_manifest_from_array_valid(): void
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
    public function test_manifest_from_array_missing_fields_fails(): void
    {
        $manifest = ThemeManifest::fromArray([]);

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertGreaterThanOrEqual(2, count($result->errors));
    }

    // -- Multiple errors accumulated ------------------------------------------

    #[Test]
    public function test_multiple_errors_accumulated(): void
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
