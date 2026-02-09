# Plugin management guide

This guide covers installing, configuring, and managing CMS plugins in Pulsar CMS, including capability review, provenance verification, and the scoped plugin sandbox.

## Overview

CMS plugins extend the functionality of Pulsar CMS without modifying core code. Plugins can add custom content types, admin pages, shortcodes, block types, and hooks. Every plugin operates within a security sandbox with declared capabilities.

## Plugin structure

Each plugin is a directory containing a `plugin.json` manifest and PHP code.

### Plugin manifest (plugin.json)

```json
{
  "slug": "contact-forms",
  "display_name": "Contact Forms",
  "version": "2.1.0",
  "description": "Adds contact form blocks and shortcodes",
  "author_name": "Acme Plugins",
  "author_url": "https://acme-plugins.example.com",
  "license": "MIT",
  "pulsar_version": ">=1.0.0",
  "capabilities": ["block_types", "shortcodes", "hooks"],
  "dependencies": {},
  "entry_point": "Acme\\ContactForms\\ContactFormsPlugin",
  "settings": {
    "notification_email": "admin@example.com",
    "recaptcha_enabled": false
  }
}
```

### Manifest fields

| Field            | Required | Description                                                  |
| ---------------- | -------- | ------------------------------------------------------------ |
| `slug`           | Yes      | URL-safe plugin identifier                                   |
| `display_name`   | Yes      | Human-readable name                                          |
| `version`        | Yes      | SemVer version string                                        |
| `description`    | No       | Plugin description                                           |
| `author_name`    | No       | Author name                                                  |
| `author_url`     | No       | Author website URL                                           |
| `license`        | No       | SPDX license identifier                                      |
| `pulsar_version` | No       | Required Pulsar version constraint                           |
| `capabilities`   | Yes      | List of declared capabilities                                |
| `dependencies`   | No       | Other plugin slugs and version constraints                   |
| `entry_point`    | Yes      | Fully qualified class name implementing `CmsPluginInterface` |
| `settings`       | No       | Plugin-specific configurable settings with defaults          |

## Plugin capabilities

Every plugin must declare its capabilities in the manifest. The CMS enforces these declarations at runtime.

| Capability      | Description                                                 |
| --------------- | ----------------------------------------------------------- |
| `content_types` | Register custom content types with the field registry       |
| `admin_pages`   | Add custom pages to the CMS admin panel                     |
| `hooks`         | Register hooks that execute during content lifecycle events |
| `shortcodes`    | Register shortcodes for inline content rendering            |
| `block_types`   | Register custom content block types                         |

### Capability review

Before enabling a plugin, review its declared capabilities:

<!-- Screenshot: Plugin capability review panel -->

1. Navigate to **Admin > CMS > Plugins** (`/admin/cms/plugins`).
2. Click on an installed plugin to view its details.
3. Review the **Capabilities** section to understand what the plugin can do.
4. Assess whether the declared capabilities are appropriate for the plugin's stated purpose.

Plugins cannot access functionality beyond their declared capabilities. The `ScopedContainerProxy` restricts container access to only the services relevant to the plugin's declared capabilities.

## Installing a plugin

### Via admin panel

1. Navigate to **Admin > CMS > Plugins** (`/admin/cms/plugins`).
2. Click **Install New Plugin**.
3. Upload a plugin archive (`.zip` file).
4. The system validates:

- Manifest presence and required fields
- Version constraint compatibility
- Capability declarations
- Signature verification (if `security.require_signed_plugins` is enabled)

5. After validation, the plugin appears in the installed plugins list in **disabled** state.

### Via API

```
POST /admin/cms/plugins
Content-Type: multipart/form-data

file: [plugin-archive.zip]
```

## Enabling and disabling plugins

Newly installed plugins are disabled by default. You must explicitly enable them.

### Enabling

1. Navigate to **Admin > CMS > Plugins** (`/admin/cms/plugins`).
2. Find the plugin in the list.
3. Click **Enable**.
4. The plugin's `CmsPluginInterface::boot()` method is called, registering its functionality.

### Disabling

1. Click **Disable** on an active plugin.
2. The plugin's hooks and registrations are removed.
3. Content created by the plugin (custom content types, blocks) remains in the database.

### Via API

```
POST /admin/cms/plugins/{id}/toggle
```

This toggles the plugin between enabled and disabled states.

## Plugin settings

Plugins can expose configurable settings.

### Viewing settings

1. Navigate to **Admin > CMS > Plugins > {id} > Settings** (`/admin/cms/plugins/{id}/settings`).
2. View all available settings with their current values and defaults.

### Updating settings

1. Modify the desired settings values.
2. Click **Save**.
3. Settings take effect immediately (no restart required).

### Via API

```
GET /admin/cms/plugins/{id}/settings
PUT /admin/cms/plugins/{id}/settings
Content-Type: application/json

{
    "notification_email": "new-admin@example.com",
    "recaptcha_enabled": true
}
```

## Plugin signature verification

For regulated environments, plugins can require cryptographic signatures.

### Configuration

```php
'security' => [
    'require_signed_plugins' => true,
    'trusted_public_keys' => [
        'base64-encoded-ed25519-public-key-1',
    ],
    'integrity_check_on_boot' => true,
],
```

### Provenance verification

The `PluginProvenanceVerifier` checks:

1. The plugin archive signature against trusted public keys
2. The integrity of all files within the archive
3. That the manifest has not been tampered with

### Integrity check on boot

When `integrity_check_on_boot` is enabled (default: `true`):

- All enabled plugins have their files verified on application boot
- Modified or missing files trigger an integrity warning
- Signed plugins have their signatures re-verified

This prevents unauthorized modifications to plugin code on the server.

## Plugin sandbox

Plugins run within a security sandbox that limits their access:

### Scoped container

The `ScopedContainerProxy` wraps the application container for each plugin:

- Plugins can only resolve services they need for their declared capabilities
- Direct database access is not available to plugins
- Container access is read-only (plugins cannot register new bindings)

### Hook execution

The `HookExecutionEngine` manages plugin hook execution:

- Hooks run in isolation with error boundaries
- A failing hook does not crash the application
- Hook execution time is bounded
- Hook output is validated before integration

### Hook registry

The `HookRegistry` tracks all registered hooks across plugins:

- Before/after content create, update, publish, archive, delete
- Before/after comment submit
- Before/after page render

## Plugin events

The plugin system dispatches events:

| Event             | When                      |
| ----------------- | ------------------------- |
| `PluginInstalled` | A new plugin is installed |
| `PluginEnabled`   | A plugin is enabled       |
| `PluginDisabled`  | A plugin is disabled      |
| `PluginDeleted`   | A plugin is uninstalled   |

These events can trigger notifications or audit log entries.

## Deleting a plugin

1. Disable the plugin first (it must not be active).
2. Navigate to **Admin > CMS > Plugins**.
3. Click **Delete** on the disabled plugin.
4. Confirm deletion.

```
DELETE /admin/cms/plugins/{id}
```

Deleting a plugin removes its code files but preserves any content or data it created (custom content types, blocks).

## Permissions

| Permission            | Role  | Description                        |
| --------------------- | ----- | ---------------------------------- |
| `cms.plugins.view`    | Admin | View installed plugins             |
| `cms.plugins.install` | Admin | Upload and install plugins         |
| `cms.plugins.manage`  | Admin | Enable, disable, configure plugins |
| `cms.plugins.delete`  | Admin | Delete installed plugins           |

## Dependencies

Plugins can declare dependencies on other plugins:

```json
{
  "dependencies": {
    "analytics-core": ">=1.0.0",
    "media-optimizer": ">=2.0.0 <3.0.0"
  }
}
```

The CMS validates dependencies during installation:

- All required plugins must be installed with compatible versions
- A plugin cannot be disabled if other enabled plugins depend on it
- Circular dependencies are detected and rejected

## Next steps

- [Theme Management](theme-management.md) - Managing themes alongside plugins
- [Security Model](../security/security-model.md) - Plugin security model
- [Settings Reference](settings-reference.md) - Plugin-related configuration
