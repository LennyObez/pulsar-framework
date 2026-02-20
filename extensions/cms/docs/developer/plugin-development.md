# CMS Plugin Development Guide

This guide covers the complete plugin development lifecycle for the Pulsar CMS, from manifest creation through packaging and distribution.

## Plugin Directory Structure

A plugin is a ZIP archive containing:

```
my-plugin/
  plugin.json              # Plugin manifest (required)
  src/
    MyPlugin.php           # Entry point implementing CmsPluginInterface
    ContentTypes/
      EventContentType.php # Custom content type definition
    Hooks/
      ContentHooks.php     # Hook callback implementations
```

## Plugin Manifest (`plugin.json`)

The manifest declares all plugin metadata. It is parsed into a `PluginManifest` DTO via `PluginManifest::fromArray()`.

### Required Fields

| Field          | Type   | Description                                                                                                              |
| -------------- | ------ | ------------------------------------------------------------------------------------------------------------------------ |
| `slug`         | string | URL-safe identifier. Lowercase alphanumeric with hyphens, 1-200 characters. Pattern: `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$` |
| `display_name` | string | Human-readable plugin name. Alternative key: `name`.                                                                     |
| `version`      | string | SemVer version string (e.g., `1.0.0`). Pattern: `^\d+\.\d+\.\d+(?:-[\w.]+)?(?:\+[\w.]+)?$`                               |

### Optional Fields

| Field            | Type   | Description                                                                      |
| ---------------- | ------ | -------------------------------------------------------------------------------- |
| `description`    | string | Plugin description for marketplace listing.                                      |
| `author_name`    | string | Plugin author name.                                                              |
| `author_url`     | string | Author website URL.                                                              |
| `license`        | string | SPDX license identifier (e.g., `MIT`).                                           |
| `pulsar_version` | string | Required Pulsar framework version constraint (e.g., `^1.0.0`).                   |
| `capabilities`   | list   | Capabilities this plugin provides (see [Capability System](#capability-system)). |
| `dependencies`   | object | Plugin slug to version constraint mapping (e.g., `{"other-plugin": "^2.0.0"}`).  |
| `entry_point`    | string | Fully qualified class name implementing `CmsPluginInterface`.                    |
| `settings`       | object | Plugin-specific configurable settings (key-value pairs).                         |

### Example Manifest

```json
{
  "slug": "event-calendar",
  "display_name": "Event Calendar",
  "version": "1.0.0",
  "description": "Adds an event content type with calendar views.",
  "author_name": "Acme Plugins",
  "author_url": "https://example.com",
  "license": "MIT",
  "pulsar_version": "^1.0.0",
  "capabilities": ["content_types", "hooks", "admin_pages"],
  "dependencies": {},
  "entry_point": "Acme\\EventCalendar\\EventCalendarPlugin",
  "settings": {
    "default_timezone": "UTC",
    "events_per_page": 20
  }
}
```

## The `CmsPluginInterface` Contract

Every plugin must implement `Pulsar\Extension\Cms\Plugins\CmsPluginInterface`:

```php
<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

interface CmsPluginInterface
{
    /**
     * Human-readable plugin name.
     */
    public function name(): string;

    /**
     * Register plugin services and bindings.
     *
     * Called once during plugin installation/boot. Use the context to
     * declare content types, shortcodes, block types, and admin pages.
     */
    public function register(CmsPluginContext $context): void;

    /**
     * Boot the plugin after all plugins have been registered.
     *
     * Called on every request where the plugin is enabled. Use the
     * context to register hooks that depend on other plugins.
     */
    public function boot(CmsPluginContext $context): void;

    /**
     * Capabilities this plugin provides.
     *
     * @return list<PluginCapability>
     */
    public function capabilities(): array;
}
```

### Lifecycle

1. **`register()`** -- Called once when the plugin is first loaded. Register content types, shortcodes, block types, and admin pages here.
2. **`boot()`** -- Called on every request where the plugin is enabled, after all plugins have been registered. Register hooks here, especially hooks that depend on other plugins being registered first.
3. **`capabilities()`** -- Returns the list of capabilities this plugin provides. Must match the `capabilities` declared in `plugin.json`.

## The `CmsPluginContext` API

The `CmsPluginContext` is the scoped API surface available to plugins during `register()` and `boot()`. All registrations are namespaced by the plugin's slug.

### Methods

#### `registerContentType(ContentTypeDefinition $definition): void`

Register a custom content type with typed field definitions. See [Content Type API](content-type-api.md) for details on building `ContentTypeDefinition` objects.

```php
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeDefinition;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeField;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;

$context->registerContentType(new ContentTypeDefinition(
    type: 'event',
    label: 'Event',
    icon: 'calendar',
    fields: [
        new ContentTypeField(
            id: 'field-event-date',
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
        new ContentTypeField(
            id: 'field-event-location',
            contentType: 'event',
            fieldKey: 'location',
            fieldType: FieldType::String,
            required: true,
            translatable: true,
            searchable: true,
            filterable: false,
            sortable: false,
            validationRules: ['max_length' => 500],
            defaultValue: null,
            sortOrder: 2,
        ),
    ],
));
```

#### `registerAdminPage(string $route, string $label, callable $handler): void`

Register a custom admin page accessible from the CMS admin panel.

```php
$context->registerAdminPage(
    route: '/admin/cms/events/calendar',
    label: 'Calendar View',
    handler: function (ServerRequestInterface $request): ResponseInterface {
        // Render the calendar admin page
        return new HtmlResponse('<h1>Event Calendar</h1>...');
    },
);
```

#### `registerHook(string $hookPoint, callable $callback, int $priority = 10): void`

Register a callback for a named hook point. Lower priority values execute first.

```php
$context->registerHook(
    hookPoint: 'content.before_publish',
    callback: function (Content $content): void {
        // Validate event-specific fields before publish
        if ($content->contentType->value === 'event') {
            // Custom validation logic
        }
    },
    priority: 5,
);
```

#### `registerShortcode(string $name, callable $handler): void`

Register a shortcode handler that transforms shortcode tags in content body.

```php
$context->registerShortcode(
    name: 'upcoming-events',
    handler: function (array $attributes): string {
        $limit = (int) ($attributes['limit'] ?? 5);
        return '<div class="upcoming-events">...</div>';
    },
);
```

#### `registerBlockType(string $name, callable $renderer): void`

Register a block type renderer for structured content blocks.

```php
$context->registerBlockType(
    name: 'event-card',
    renderer: function (array $data): string {
        $title = htmlspecialchars($data['title'] ?? '', ENT_QUOTES);
        $date = htmlspecialchars($data['date'] ?? '', ENT_QUOTES);
        return "<div class=\"event-card\"><h3>{$title}</h3><time>{$date}</time></div>";
    },
);
```

#### `container(): ScopedContainerProxy`

Access the scoped container proxy to resolve allowed services.

```php
$cache = $context->container()->get(TaggedCacheInterface::class);
$logger = $context->container()->get(LoggerInterface::class);
```

#### `pluginSlug(): string`

Get the plugin's slug identifier.

## Scoped Container Proxy

The `ScopedContainerProxy` limits which services a plugin can resolve. This prevents plugins from accessing sensitive framework internals.

### Allowed Services

| Service Interface                                         | Purpose             |
| --------------------------------------------------------- | ------------------- |
| `Psr\Log\LoggerInterface`                                 | Application logging |
| `Pulsar\Cache\Application\TaggedCacheInterface`           | Tag-based caching   |
| `Pulsar\Event\EventDispatcherInterface`                   | Event dispatch      |
| `Pulsar\Extension\Cms\Content\ContentRepositoryInterface` | Content queries     |
| `Pulsar\Extension\Cms\Media\MediaRepositoryInterface`     | Media asset queries |
| `Pulsar\Extension\Cms\Settings\SettingsServiceInterface`  | CMS settings access |

### Denied Services

Any service not in the allowed list throws a `RuntimeException`:

```
Plugin 'my-plugin' is not allowed to access service 'Pulsar\Security\Crypto\MasterKey'
```

Explicitly denied categories include:

- `MasterKey` and all cryptographic services
- `AuditLoggerInterface`
- `RoleRegistryInterface`
- All `Internal\` namespaced classes
- `ConnectionInterface` (no direct database access)

## Capability System

Plugins declare capabilities in their manifest to indicate what they provide. The CMS uses these declarations for dependency resolution and admin UI.

### Available Capabilities

| Capability     | Value           | Description                           |
| -------------- | --------------- | ------------------------------------- |
| `ContentTypes` | `content_types` | Plugin registers custom content types |
| `AdminPages`   | `admin_pages`   | Plugin registers admin panel pages    |
| `Hooks`        | `hooks`         | Plugin registers hook callbacks       |
| `Shortcodes`   | `shortcodes`    | Plugin registers shortcode handlers   |
| `BlockTypes`   | `block_types`   | Plugin registers block type renderers |

Declare capabilities in both `plugin.json` and the `capabilities()` method:

```php
public function capabilities(): array
{
    return [
        PluginCapability::ContentTypes,
        PluginCapability::Hooks,
        PluginCapability::AdminPages,
    ];
}
```

## Hook System

### Registering Hooks

Register hooks in `boot()` using `registerHook()`:

```php
public function boot(CmsPluginContext $context): void
{
    $context->registerHook('content.before_save', $this->onBeforeSave(...), priority: 10);
    $context->registerHook('content.after_publish', $this->onAfterPublish(...), priority: 20);
}
```

### Execution Order

Hooks are executed in ascending priority order (lower values execute first). When multiple plugins register callbacks for the same hook point at the same priority, they execute in registration order.

### Hook Points

See the [Hook Reference](hook-reference.md) for all available hook points and their callback signatures.

## Guardrails

The CMS enforces strict guardrails on plugin execution to protect system stability:

### Memory Limits

Each hook callback is monitored for memory consumption. If a single callback allocates more than **32 MB** of memory, the failure is recorded and logged:

```
Hook callback from plugin "my-plugin" used 35651584 bytes (limit: 33554432)
```

### Circuit Breaker

The circuit breaker tracks failures per plugin within a sliding window:

| Parameter         | Value                             |
| ----------------- | --------------------------------- |
| Failure threshold | 10 failures                       |
| Window duration   | 300 seconds (5 minutes)           |
| Action on trip    | Plugin hooks are silently skipped |

When a plugin accumulates 10 failures within 5 minutes, the circuit breaker trips and all hook callbacks from that plugin are skipped until the application restarts. This is logged as a critical event and recorded in the audit log.

### Output Buffering

All hook callbacks execute inside an output buffer (`ob_start()`/`ob_end_clean()`). Any output produced by a callback (echo, print, var_dump) is captured and discarded. Hooks must not produce output; they should modify state or return values.

### Error Isolation

Exceptions thrown by hook callbacks are caught, logged, and counted toward the circuit breaker threshold. They do not propagate to the caller, ensuring one plugin's failure cannot crash another plugin or the CMS itself.

## Plugin Lifecycle

### Installation

```
Upload archive --> Extract (Zip Slip protected) --> Validate manifest
  --> Verify provenance (if required) --> Store files and DB record
  --> PluginInstalled event dispatched
```

The `CmsPluginManagerInterface::install()` method handles the full installation pipeline:

1. Archive extraction with path traversal protection
2. Manifest parsing and validation
3. Cryptographic provenance verification (if `requireSignedPlugins` is enabled)
4. File storage in the configured plugins directory
5. Database record creation with manifest hash and package hash

### Enable/Disable

```php
$manager->enable($pluginId, $enabledBy);   // PluginEnabled event
$manager->disable($pluginId, $disabledBy); // PluginDisabled event
```

### Boot Order

When the application boots, `CmsPluginManagerInterface::bootAll()` loads all enabled plugins in dependency-resolved order:

1. Resolve plugin dependency graph from manifest `dependencies`
2. Create `CmsPluginContext` for each plugin with `ScopedContainerProxy` and `HookRegistry`
3. Call `register()` on each plugin in dependency order
4. Call `boot()` on each plugin in dependency order
5. Check circuit breaker status; skip broken plugins

### Deletion

```php
$manager->delete($pluginId, $deletedBy, $reason); // PluginDeleted event
```

Only disabled plugins can be deleted. Attempting to delete an enabled plugin throws `CmsException`.

## Provenance Verification

Plugin packages can optionally require cryptographic signatures for supply-chain security.

### Configuration

```php
return [
    'security' => [
        'require_signed_plugins' => true,
        'trusted_public_keys' => [
            'base64-encoded-ed25519-public-key-1',
            'base64-encoded-ed25519-public-key-2',
        ],
        'integrity_check_on_boot' => true,
    ],
];
```

### Verification Levels

| Setting                         | Behavior                                                                                     |
| ------------------------------- | -------------------------------------------------------------------------------------------- |
| `require_signed_plugins: false` | Signature is optional; unsigned plugins are accepted                                         |
| `require_signed_plugins: true`  | Packages must have a valid Ed25519 signature matching a trusted public key                   |
| `integrity_check_on_boot: true` | File hashes are verified against the recorded `manifestHash` and `packageHash` on every boot |

## Complete Minimal Plugin Example

### `plugin.json`

```json
{
  "slug": "hello-world",
  "display_name": "Hello World",
  "version": "1.0.0",
  "description": "A minimal example plugin that adds a greeting shortcode.",
  "author_name": "Your Name",
  "license": "MIT",
  "pulsar_version": "^1.0.0",
  "capabilities": ["shortcodes"],
  "entry_point": "HelloWorld\\HelloWorldPlugin"
}
```

### `src/HelloWorldPlugin.php`

```php
<?php

declare(strict_types=1);

namespace HelloWorld;

use Pulsar\Extension\Cms\Plugins\CmsPluginContext;
use Pulsar\Extension\Cms\Plugins\CmsPluginInterface;
use Pulsar\Extension\Cms\Plugins\PluginCapability;

final class HelloWorldPlugin implements CmsPluginInterface
{
    public function name(): string
    {
        return 'Hello World';
    }

    public function register(CmsPluginContext $context): void
    {
        $context->registerShortcode('hello', function (array $attributes): string {
            $name = htmlspecialchars($attributes['name'] ?? 'World', ENT_QUOTES);
            return "<span class=\"greeting\">Hello, {$name}!</span>";
        });
    }

    public function boot(CmsPluginContext $context): void
    {
        // No boot-time hooks needed for this simple plugin.
    }

    public function capabilities(): array
    {
        return [
            PluginCapability::Shortcodes,
        ];
    }
}
```

Usage in content body:

```
Welcome to our site! [hello name="Developer"]
```

Renders as:

```html
Welcome to our site! <span class="greeting">Hello, Developer!</span>
```

## Related Documentation

- [Hook Reference](hook-reference.md) -- All available hook points and callback signatures
- [Content Type API](content-type-api.md) -- Defining custom content types programmatically
- [Architecture Overview](architecture.md) -- Plugin module boundaries and security model
