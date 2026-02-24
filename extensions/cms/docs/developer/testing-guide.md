# Testing CMS Extensions Guide

This guide covers how to test CMS plugins, themes, content types, and custom functionality using Pulsar's testing infrastructure.

## Test Directory Structure

CMS tests are organized by module under `tests/Unit/Extension/Cms/`:

```
tests/Unit/Extension/Cms/
  Comments/
  Commerce/
  Config/
  Content/
  Dashboard/
  EventStore/
  Exception/
  FieldRegistry/
  LiveCss/
  Media/
  Navigation/
  Plugins/
  Search/
  Security/
  Seo/
  Studio/
  Themes/
  Tools/
  Users/
  Workflow/
```

## Running Tests

### Run All CMS Tests

```bash
vendor/bin/phpunit -c tools/php/phpunit.xml --filter 'Extension\\Cms'
```

### Run a Specific Module

```bash
vendor/bin/phpunit -c tools/php/phpunit.xml --filter 'Extension\\Cms\\Content'
```

### Run a Single Test Class

```bash
vendor/bin/phpunit -c tools/php/phpunit.xml --filter ContentTest
```

### Run a Single Test Method

```bash
vendor/bin/phpunit -c tools/php/phpunit.xml --filter 'ContentTest::test_create_returns_draft_content'
```

## Test Conventions

### PHPUnit Attributes

All CMS tests use PHPUnit attributes for metadata:

```php
<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Content::class)]
final class ContentTest extends TestCase
{
    #[Test]
    public function test_create_returns_draft_content(): void
    {
        // Test implementation
    }
}
```

- `#[CoversClass(...)]` declares which class the test covers (for code coverage reporting)
- `#[Test]` marks individual test methods
- Test methods use the `test_` prefix naming convention

### Test IDs

Use deterministic UUIDv7-format IDs for test fixtures:

```php
private const string CONTENT_ID = '01912345-6789-7abc-8def-0123456789ab';
private const string AUTHOR_ID = '01912345-6789-7abc-8def-0123456789cd';
private const string TENANT_ID = '01912345-0000-7abc-8def-000000000001';
```

## Testing Content Entities

### Testing Content Creation

```php
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;

#[Test]
public function test_create_returns_draft_content(): void
{
    $content = Content::create(
        id: '01912345-6789-7abc-8def-0123456789ab',
        contentType: ContentType::Article,
        authorId: '01912345-6789-7abc-8def-0123456789cd',
    );

    self::assertSame(PublishingStatus::Draft, $content->status);
    self::assertNull($content->publishedAt);
    self::assertSame(CommentPolicy::Inherit, $content->commentPolicy);
    self::assertSame(DataClassification::Public, $content->dataClassification);
}
```

### Testing State Transitions

```php
use Pulsar\Extension\Cms\Exception\CmsException;

#[Test]
public function test_publish_transitions_draft_to_published(): void
{
    $content = Content::create(
        id: '01912345-6789-7abc-8def-0123456789ab',
        contentType: ContentType::Article,
        authorId: '01912345-6789-7abc-8def-0123456789cd',
    );

    $published = $content->publish();

    self::assertSame(PublishingStatus::Published, $published->status);
    self::assertNotNull($published->publishedAt);
}

#[Test]
public function test_publish_from_archived_throws(): void
{
    $content = Content::create(
        id: '01912345-6789-7abc-8def-0123456789ab',
        contentType: ContentType::Article,
        authorId: '01912345-6789-7abc-8def-0123456789cd',
    );

    $published = $content->publish();
    $archived = $published->archive();

    $this->expectException(CmsException::class);
    $archived->publish(); // Cannot publish directly from archived
}
```

### Testing Editorial Workflow

```php
#[Test]
public function test_editorial_workflow_transitions(): void
{
    $content = Content::create(
        id: '01912345-6789-7abc-8def-0123456789ab',
        contentType: ContentType::Article,
        authorId: '01912345-6789-7abc-8def-0123456789cd',
    );

    // Draft -> InReview (requires editorial workflow)
    $inReview = $content->submitForReview();
    self::assertSame(PublishingStatus::InReview, $inReview->status);

    // InReview -> Approved
    $approved = $inReview->approve();
    self::assertSame(PublishingStatus::Approved, $approved->status);

    // Approved -> Published (requires editorial workflow flag)
    $published = $approved->publish(editorialWorkflow: true);
    self::assertSame(PublishingStatus::Published, $published->status);
}
```

## Testing Content Translations

```php
use Pulsar\Extension\Cms\Content\ContentTranslation;

#[Test]
public function test_create_translation_with_valid_slug(): void
{
    $translation = ContentTranslation::create(
        id: '01912345-6789-7abc-8def-111111111111',
        contentId: '01912345-6789-7abc-8def-0123456789ab',
        locale: 'en',
        title: 'Getting Started',
        slugSegment: 'getting-started',
        path: 'docs/getting-started',
        body: '<p>Welcome to the documentation.</p>',
    );

    self::assertSame('en', $translation->locale);
    self::assertSame('getting-started', $translation->slugSegment);
}

#[Test]
public function test_invalid_slug_throws_exception(): void
{
    $this->expectException(CmsException::class);

    ContentTranslation::create(
        id: '01912345-6789-7abc-8def-111111111111',
        contentId: '01912345-6789-7abc-8def-0123456789ab',
        locale: 'en',
        title: 'Test',
        slugSegment: 'Invalid Slug!',
        path: 'test',
        body: '<p>Test</p>',
    );
}
```

## Mocking Repository Interfaces

Use PHPUnit mocks or stubs for repository interfaces:

```php
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;

private ContentRepositoryInterface $repository;

protected function setUp(): void
{
    $this->repository = $this->createMock(ContentRepositoryInterface::class);
}

#[Test]
public function test_find_by_path_returns_content(): void
{
    $content = Content::create(
        id: '01912345-6789-7abc-8def-0123456789ab',
        contentType: ContentType::Page,
        authorId: '01912345-6789-7abc-8def-0123456789cd',
    );

    $this->repository
        ->expects(self::once())
        ->method('findByPath')
        ->with('en', 'about')
        ->willReturn($content);

    $result = $this->repository->findByPath('en', 'about');
    self::assertNotNull($result);
    self::assertSame(ContentType::Page, $result->contentType);
}
```

## Testing Plugin Hooks

### Testing Hook Registration

```php
use Pulsar\Extension\Cms\Plugins\HookRegistry;

#[Test]
public function test_hook_registry_stores_callbacks(): void
{
    $registry = new HookRegistry();
    $called = false;

    $registry->register(
        hookPoint: 'content.published',
        callback: static function () use (&$called) { $called = true; },
        priority: 10,
        pluginSlug: 'test-plugin',
    );

    self::assertTrue($registry->has('content.published'));
    self::assertFalse($registry->has('content.deleted'));

    $callbacks = $registry->getCallbacks('content.published');
    self::assertCount(1, $callbacks);
    self::assertSame('test-plugin', $callbacks[0]['pluginSlug']);
}
```

### Testing Hook Execution with Error Isolation

```php
use Pulsar\Extension\Cms\Internal\Plugins\HookExecutionEngine;
use Psr\Log\NullLogger;
use Pulsar\Audit\AuditLoggerInterface;

#[Test]
public function test_hook_exception_does_not_propagate(): void
{
    $hookRegistry = new HookRegistry();
    $auditLogger = $this->createStub(AuditLoggerInterface::class);
    $engine = new HookExecutionEngine($hookRegistry, $auditLogger, new NullLogger());

    $called = false;

    $hookRegistry->register('before_save', static function () {
        throw new RuntimeException('Plugin error');
    }, 10, 'failing-plugin');

    $hookRegistry->register('before_save', static function () use (&$called) {
        $called = true;
    }, 20, 'good-plugin');

    $engine->execute('before_save');

    self::assertTrue($called, 'Second hook should execute even when first throws');
}
```

### Testing Circuit Breaker

```php
#[Test]
public function test_circuit_breaker_trips_after_threshold(): void
{
    $hookRegistry = new HookRegistry();
    $auditLogger = $this->createStub(AuditLoggerInterface::class);
    $engine = new HookExecutionEngine($hookRegistry, $auditLogger, new NullLogger());

    // Register a hook that always fails
    $hookRegistry->register('always_fail', static function () {
        throw new RuntimeException('Failure');
    }, 10, 'broken-plugin');

    // Execute 10 times to trip the circuit breaker
    for ($i = 0; $i < 10; $i++) {
        $engine->execute('always_fail');
    }

    self::assertTrue($engine->isCircuitBroken('broken-plugin'));
    self::assertContains('broken-plugin', $engine->getCircuitBrokenPlugins());
}
```

## Testing Manifest Validation

### Theme Manifest

```php
use Pulsar\Extension\Cms\Internal\Themes\ThemeManifestValidator;
use Pulsar\Extension\Cms\Themes\ThemeManifest;

#[Test]
public function test_valid_theme_manifest_passes(): void
{
    $validator = new ThemeManifestValidator();
    $manifest = ThemeManifest::fromArray([
        'slug' => 'my-theme',
        'display_name' => 'My Theme',
        'version' => '1.0.0',
    ]);

    $result = $validator->validate($manifest);
    self::assertTrue($result->isValid);
}

#[Test]
public function test_missing_slug_fails_validation(): void
{
    $validator = new ThemeManifestValidator();
    $manifest = ThemeManifest::fromArray([
        'display_name' => 'My Theme',
        'version' => '1.0.0',
    ]);

    $result = $validator->validate($manifest);
    self::assertFalse($result->isValid);
    self::assertNotEmpty($result->errors);
}
```

### Plugin Manifest

```php
use Pulsar\Extension\Cms\Internal\Plugins\PluginManifestValidator;
use Pulsar\Extension\Cms\Plugins\PluginManifest;

#[Test]
public function test_valid_plugin_manifest_passes(): void
{
    $validator = new PluginManifestValidator();
    $manifest = PluginManifest::fromArray([
        'slug' => 'my-plugin',
        'display_name' => 'My Plugin',
        'version' => '1.0.0',
        'capabilities' => ['hooks'],
        'entry_point' => 'Acme\\MyPlugin\\Plugin',
    ]);

    $result = $validator->validate($manifest);
    self::assertTrue($result->isValid);
}

#[Test]
public function test_invalid_semver_fails_validation(): void
{
    $validator = new PluginManifestValidator();
    $manifest = PluginManifest::fromArray([
        'slug' => 'my-plugin',
        'display_name' => 'My Plugin',
        'version' => 'not-semver',
    ]);

    $result = $validator->validate($manifest);
    self::assertFalse($result->isValid);
}
```

## Testing Custom Field Types

```php
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeDefinition;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeField;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;

#[Test]
public function test_field_type_maps_to_correct_column(): void
{
    self::assertSame('value_string', FieldType::String->valueColumn());
    self::assertSame('value_int', FieldType::Int->valueColumn());
    self::assertSame('value_float', FieldType::Float->valueColumn());
    self::assertSame('value_bool', FieldType::Bool->valueColumn());
    self::assertSame('value_datetime', FieldType::Date->valueColumn());
    self::assertSame('value_datetime', FieldType::DateTime->valueColumn());
    self::assertSame('value_string', FieldType::Enum->valueColumn());
    self::assertSame('value_json', FieldType::Relation->valueColumn());
    self::assertSame('value_json', FieldType::Json->valueColumn());
    self::assertSame('value_json', FieldType::Media->valueColumn());
    self::assertSame('value_json', FieldType::RichText->valueColumn());
    self::assertSame('value_string', FieldType::Color->valueColumn());
    self::assertSame('value_string', FieldType::Url->valueColumn());
    self::assertSame('value_string', FieldType::Email->valueColumn());
}

#[Test]
public function test_content_type_definition_holds_fields(): void
{
    $definition = new ContentTypeDefinition(
        type: 'event',
        label: 'Event',
        icon: 'calendar',
        fields: [
            new ContentTypeField(
                id: 'field-1',
                contentType: 'event',
                fieldKey: 'event_date',
                fieldType: FieldType::DateTime,
                required: true,
                translatable: false,
                searchable: true,
                filterable: true,
                sortable: true,
                validationRules: [],
                defaultValue: null,
                sortOrder: 1,
            ),
        ],
    );

    self::assertSame('event', $definition->type);
    self::assertSame('Event', $definition->label);
    self::assertCount(1, $definition->fields);
    self::assertSame(FieldType::DateTime, $definition->fields[0]->fieldType);
}
```

## Testing SEO Services

```php
use Pulsar\Extension\Cms\Seo\MetaTagCollection;
use Pulsar\Extension\Cms\Seo\JsonLdCollection;

#[Test]
public function test_meta_tag_collection_renders_html(): void
{
    $meta = new MetaTagCollection(
        title: 'Test Page',
        description: 'A test page description',
        canonical: 'https://example.com/test',
        robots: 'index, follow',
        ogTags: ['og:title' => 'Test Page'],
        hreflangLinks: ['en' => 'https://example.com/en/test'],
    );

    $html = $meta->toHtml();
    self::assertStringContainsString('<title>Test Page</title>', $html);
    self::assertStringContainsString('name="description"', $html);
    self::assertStringContainsString('rel="canonical"', $html);
    self::assertStringContainsString('property="og:title"', $html);
    self::assertStringContainsString('hreflang="en"', $html);
}

#[Test]
public function test_json_ld_collection_renders_script(): void
{
    $jsonLd = new JsonLdCollection([
        [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => 'Test Article',
        ],
    ]);

    $script = $jsonLd->toScript();
    self::assertStringContainsString('application/ld+json', $script);
    self::assertStringContainsString('"headline":"Test Article"', $script);
}
```

## Testing Configuration DTOs

```php
use Pulsar\Extension\Cms\Config\CmsConfig;

#[Test]
public function test_cms_config_defaults(): void
{
    $config = CmsConfig::fromArray([]);

    self::assertSame('en', $config->defaultLocale);
    self::assertSame(['en'], $config->supportedLocales);
    self::assertFalse($config->editorialWorkflow);
    self::assertFalse($config->eventSourcing);
    self::assertNull($config->commerce);
}

#[Test]
public function test_cms_config_from_array(): void
{
    $config = CmsConfig::fromArray([
        'default_locale' => 'fr',
        'supported_locales' => ['fr', 'en', 'de'],
        'editorial_workflow' => true,
        'event_sourcing' => true,
        'commerce' => [
            'currency' => 'EUR',
        ],
    ]);

    self::assertSame('fr', $config->defaultLocale);
    self::assertCount(3, $config->supportedLocales);
    self::assertTrue($config->editorialWorkflow);
    self::assertNotNull($config->commerce);
}
```

## Testing Import/Export

```php
use Pulsar\Extension\Cms\Tools\SiteDefinition;
use InvalidArgumentException;

#[Test]
public function test_site_definition_parses_valid_json(): void
{
    $json = json_encode([
        'version' => '1.0',
        'site' => ['name' => 'Test Site', 'url' => 'https://example.com'],
        'taxonomies' => [],
        'content' => [],
        'menus' => [],
        'media' => [],
    ]);

    $definition = SiteDefinition::fromJson($json);
    self::assertSame('Test Site', $definition->site['name']);
    self::assertSame([], $definition->taxonomies);
}

#[Test]
public function test_site_definition_rejects_invalid_version(): void
{
    $json = json_encode([
        'version' => '2.0',
        'site' => ['name' => 'Test'],
    ]);

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Unsupported site definition version');
    SiteDefinition::fromJson($json);
}

#[Test]
public function test_site_definition_requires_site_key(): void
{
    $json = json_encode(['version' => '1.0']);

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Missing required keys');
    SiteDefinition::fromJson($json);
}
```

## Integration Test Patterns

### Setting Up the CMS Test Environment

For integration tests that require the full CMS stack, create a test case base class:

```php
<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\CmsExtension;
use Pulsar\Extension\Cms\CmsServiceProvider;
use Pulsar\Extension\Cms\Config\CmsConfig;

abstract class CmsIntegrationTestCase extends TestCase
{
    protected ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = new Container();

        // Bind a test database connection
        $this->container->instance(
            ConnectionInterface::class,
            $this->createTestConnection(),
        );

        // Bind CMS config with test defaults
        $this->container->instance(
            CmsConfig::class,
            CmsConfig::fromArray([
                'editorial_workflow' => true,
                'event_sourcing' => false,
            ]),
        );

        // Register CMS services
        $provider = new CmsServiceProvider();
        $provider->register($this->container);
    }

    abstract protected function createTestConnection(): ConnectionInterface;
}
```

### Testing Repository Implementations

```php
final class ContentRepositoryIntegrationTest extends CmsIntegrationTestCase
{
    #[Test]
    public function test_save_and_find_by_id(): void
    {
        $repository = $this->container->get(ContentRepositoryInterface::class);

        $content = Content::create(
            id: '01912345-6789-7abc-8def-0123456789ab',
            contentType: ContentType::Article,
            authorId: '01912345-6789-7abc-8def-0123456789cd',
        );

        $repository->save($content);

        $found = $repository->findById($content->id);
        self::assertNotNull($found);
        self::assertSame($content->id, $found->id);
    }
}
```

## Quality Gates

After writing tests, verify the full quality pipeline:

```bash
# 1. Run CMS tests
vendor/bin/phpunit -c tools/php/phpunit.xml --filter 'Extension\\Cms'

# 2. Static analysis
php -d memory_limit=1G vendor/bin/phpstan analyse -c tools/php/phpstan.neon
vendor/bin/psalm -c tools/php/psalm.xml

# 3. Code style
vendor/bin/php-cs-fixer fix --dry-run --diff

# 4. Full test suite (ensure no regressions)
composer test
```

## Related Documentation

- [Architecture Overview](architecture.md) - Module structure and dependencies
- [Plugin Development Guide](plugin-development.md) - Plugin contract for testing
- [Content Type API](content-type-api.md) - Field types and validation for test data
