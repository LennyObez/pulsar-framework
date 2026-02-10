<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Plugins;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Plugins\PluginManifestValidator;
use Pulsar\Extension\Cms\Plugins\PluginManifest;

use function count;
use function implode;
use function str_repeat;

#[CoversClass(PluginManifestValidator::class)]
final class CmsPluginManifestValidatorTest extends TestCase
{
    private PluginManifestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PluginManifestValidator();
    }

    // -- Valid manifests ------------------------------------------------------

    #[Test]
    public function validManifestPasses(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
            description: 'A test plugin',
            authorName: 'Test Author',
            license: 'MIT',
            capabilities: ['hooks', 'shortcodes'],
            entryPoint: 'MyPlugin\\Main',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function validManifestWithAllCapabilities(): void
    {
        $manifest = new PluginManifest(
            slug: 'full-plugin',
            displayName: 'Full Plugin',
            version: '2.0.0',
            capabilities: ['content_types', 'hooks', 'admin_pages', 'shortcodes', 'block_types'],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validManifestWithDependencies(): void
    {
        $manifest = new PluginManifest(
            slug: 'dep-plugin',
            displayName: 'Dep Plugin',
            version: '1.0.0',
            dependencies: ['other-plugin' => '^1.0', 'another' => '>=2.0.0'],
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }

    // -- Missing required fields ---------------------------------------------

    #[Test]
    public function missingSlugFails(): void
    {
        $manifest = new PluginManifest(
            slug: '',
            displayName: 'My Plugin',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertNotEmpty($result->errors);

        $joined = implode(' ', $result->errors);
        self::assertStringContainsString('slug', $joined);
    }

    #[Test]
    public function missingNameFails(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: '',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);

        $joined = implode(' ', $result->errors);
        self::assertStringContainsString('name', $joined);
    }

    #[Test]
    public function missingVersionFails(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '0.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);

        $joined = implode(' ', $result->errors);
        self::assertStringContainsString('version', $joined);
    }

    #[Test]
    public function emptyVersionFails(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    // -- Invalid version format ----------------------------------------------

    #[Test]
    public function invalidVersionNotSemverFails(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: 'not-a-version',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);

        $joined = implode(' ', $result->errors);
        self::assertStringContainsString('SemVer', $joined);
    }

    #[Test]
    public function versionMissingPatchFails(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '1.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    // -- Invalid capability values -------------------------------------------

    #[Test]
    public function invalidCapabilityFails(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
            capabilities: ['hooks', 'nonexistent_capability'],
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);

        $joined = implode(' ', $result->errors);
        self::assertStringContainsString('nonexistent_capability', $joined);
    }

    #[Test]
    public function multipleInvalidCapabilitiesAccumulateErrors(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
            capabilities: ['bad1', 'bad2'],
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertGreaterThanOrEqual(2, count($result->errors));
    }

    // -- Invalid slug format -------------------------------------------------

    #[Test]
    public function slugWithUppercaseFails(): void
    {
        $manifest = new PluginManifest(
            slug: 'My-Plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function slugStartingWithHyphenFails(): void
    {
        $manifest = new PluginManifest(
            slug: '-my-plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function slugExceedingMaxLengthFails(): void
    {
        $manifest = new PluginManifest(
            slug: str_repeat('a', 201),
            displayName: 'My Plugin',
            version: '1.0.0',
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);

        $joined = implode(' ', $result->errors);
        self::assertStringContainsString('200', $joined);
    }

    // -- Invalid dependency constraints --------------------------------------

    #[Test]
    public function invalidDependencyConstraintFails(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
            dependencies: ['dep' => 'invalid constraint!!'],
        );

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);

        $joined = implode(' ', $result->errors);
        self::assertStringContainsString('dep', $joined);
    }

    // -- Warnings for missing optional fields --------------------------------

    #[Test]
    public function missingDescriptionProducesWarning(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
            description: null,
            authorName: 'Author',
            license: 'MIT',
            entryPoint: 'MyPlugin\\Main',
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
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
            description: 'Desc',
            authorName: null,
            license: 'MIT',
            entryPoint: 'MyPlugin\\Main',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);

        $joined = implode(' ', $result->warnings);
        self::assertStringContainsString('author', $joined);
    }

    #[Test]
    public function missingLicenseProducesWarning(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
            description: 'Desc',
            authorName: 'Author',
            license: null,
            entryPoint: 'MyPlugin\\Main',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);

        $joined = implode(' ', $result->warnings);
        self::assertStringContainsString('license', $joined);
    }

    #[Test]
    public function missingEntryPointProducesWarning(): void
    {
        $manifest = new PluginManifest(
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '1.0.0',
            description: 'Desc',
            authorName: 'Author',
            license: 'MIT',
            entryPoint: null,
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);

        $joined = implode(' ', $result->warnings);
        self::assertStringContainsString('entry_point', $joined);
    }

    #[Test]
    public function fullyPopulatedManifestNoWarnings(): void
    {
        $manifest = new PluginManifest(
            slug: 'complete-plugin',
            displayName: 'Complete Plugin',
            version: '1.0.0',
            description: 'A complete plugin',
            authorName: 'Test Author',
            license: 'MIT',
            entryPoint: 'MyPlugin\\Main',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
        self::assertSame([], $result->warnings);
    }

    // -- Multiple errors accumulated -----------------------------------------

    #[Test]
    public function multipleErrorsAccumulated(): void
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

    // -- fromArray factory ---------------------------------------------------

    #[Test]
    public function manifestFromArrayValid(): void
    {
        $manifest = PluginManifest::fromArray([
            'slug' => 'json-plugin',
            'name' => 'JSON Plugin',
            'version' => '2.1.0',
        ]);

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
        self::assertSame('json-plugin', $manifest->slug);
        self::assertSame('JSON Plugin', $manifest->displayName);
    }

    #[Test]
    public function manifestFromArrayMissingFieldsFails(): void
    {
        $manifest = PluginManifest::fromArray([]);

        $result = $this->validator->validate($manifest);

        self::assertFalse($result->isValid);
        self::assertGreaterThanOrEqual(2, count($result->errors));
    }

    // -- Valid prerelease version --------------------------------------------

    #[Test]
    public function validPrereleaseVersion(): void
    {
        $manifest = new PluginManifest(
            slug: 'beta-plugin',
            displayName: 'Beta Plugin',
            version: '1.0.0-beta.1',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validBuildMetadataVersion(): void
    {
        $manifest = new PluginManifest(
            slug: 'build-plugin',
            displayName: 'Build Plugin',
            version: '1.0.0+build.123',
        );

        $result = $this->validator->validate($manifest);

        self::assertTrue($result->isValid);
    }
}
