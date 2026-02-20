# CMS Extension Architecture

The Pulsar CMS extension provides a complete content management system for regulated, mission-critical domains. It is organized into 18 modules, each with strict boundaries and well-defined responsibilities.

## Module Map

| Module            | Namespace        | Responsibility                                                                                                           |
| ----------------- | ---------------- | ------------------------------------------------------------------------------------------------------------------------ |
| **Content**       | `Content\`       | Content aggregate root, translations, revisions, blocks, redirects, slug generation, safe HTML policy, path computation  |
| **Taxonomy**      | `Taxonomy\`      | Vocabularies and hierarchical terms with translations                                                                    |
| **Navigation**    | `Navigation\`    | Menus, menu items, breadcrumbs                                                                                           |
| **Workflow**      | `Workflow\`      | Editorial review pipeline, pessimistic content locking                                                                   |
| **FieldRegistry** | `FieldRegistry\` | Custom content types and typed field definitions                                                                         |
| **EventStore**    | `EventStore\`    | Append-only event log and atomic content snapshots                                                                       |
| **Media**         | `Media\`         | File upload, image processing, derivative generation, security validation                                                |
| **Comments**      | `Comments\`      | Threaded comments with moderation and safe HTML                                                                          |
| **Search**        | `Search\`        | Full-text search with analytics tracking                                                                                 |
| **SEO**           | `Seo\`           | Meta tags, structured data (JSON-LD), sitemaps, robots.txt, RSS/Atom feeds, redirect management, link health checking    |
| **Themes**        | `Themes\`        | Theme lifecycle (install, activate, preview, rollback), manifest validation, provenance verification, asset resolution   |
| **Plugins**       | `Plugins\`       | Plugin lifecycle, manifest validation, provenance verification, hook registry, scoped container proxy, capability system |
| **LiveCss**       | `LiveCss\`       | Live CSS overrides with versioning, CSS validation, CSP hash computation, theme token resolution                         |
| **Commerce**      | `Commerce\`      | Products, orders, checkout, invoices, digital assets, promotions, coupons, tax calculation, payment gateway integration  |
| **Users**         | `Users\`         | CMS user management, two-factor authentication                                                                           |
| **Settings**      | `Settings\`      | Key-value settings with locale support                                                                                   |
| **Tools**         | `Tools\`         | Import/export, site definition import, backups, GDPR data tools                                                          |
| **Dashboard**     | `Dashboard\`     | Admin dashboard widgets (traffic, SEO, system health, moderation, audit)                                                 |

## Extension Entry Point

The CMS is registered as a Pulsar extension via `CmsExtension`, which implements three lifecycle interfaces:

```
ExtensionInterface       -- name(), register(), boot(), providers()
PreBootExtensionInterface  -- preBoot()
PostBootExtensionInterface -- postBoot()
```

Source: `extensions/cms/src/CmsExtension.php`

## Boot Sequence (4 Phases)

### Phase 1: Service Provider Registration

`CmsServiceProvider::register()` is invoked by the framework. It binds all repository interfaces to their database-backed implementations and wires service dependencies. This phase has three sub-steps:

1. **`bindRepositories()`** -- Binds 25+ repository interfaces to `Db*Repository` implementations using the `ConnectionInterface`.
2. **`bindServices()`** -- Creates and registers service stacks (Media, Comments, Search, SEO, Themes, Plugins, Tools, LiveCSS, Commerce).
3. **`registerPermissions()`** -- Registers 9 CMS roles and 50+ permissions with Pulsar's `RoleRegistryInterface`.

### Phase 2: Pre-Boot (`preBoot`)

Runs before route registration. Performs two operations:

1. **Configuration loading** -- Loads `config/cms.php` if present, parses it into a `CmsConfig` DTO via `CmsConfig::fromArray()`, and binds it to the container. Falls back to defaults if no config file exists.
2. **Breadcrumb generator binding** -- Creates and binds `BreadcrumbGenerator` if its dependencies (`ContentRepositoryInterface`, `ContentTranslationRepositoryInterface`) are available.

### Phase 3: Boot (Route Registration)

`boot()` registers all HTTP routes in two groups:

- **Public routes** -- Locale-aware content rendering (`/{locale}/{path}` or `/{path}` for the default locale), commerce checkout, digital downloads, and payment webhooks.
- **Admin routes** -- All admin panel endpoints under `/admin/cms/` covering content CRUD, taxonomies, menus, fields, settings, themes, plugins, users, commerce, live CSS, import/export, backups, and 2FA.

### Phase 4: Post-Boot (`postBoot`)

Runs after all extensions have booted:

1. **Settings cache warming** -- Pre-loads settings for the default locale to avoid cold-cache latency.
2. **`CmsReady` event dispatch** -- Signals that the CMS is fully initialized. Plugins and other extensions can listen for this event.

## Configuration Architecture

All configuration flows through `CmsConfig`, a readonly DTO loaded from `config/cms.php`:

```
CmsConfig
 +-- defaultLocale: string          ("en")
 +-- supportedLocales: list<string>  (["en"])
 +-- defaultLocaleInUrl: bool        (false)
 +-- editorialWorkflow: bool         (false)
 +-- eventSourcing: bool             (false)
 +-- atomicSnapshots: bool           (false)
 +-- maxHierarchyDepth: int          (10)
 +-- cache: CmsCacheConfig
 +-- media: MediaConfig
 +-- comments: CommentsConfig
 +-- seo: SeoConfig
 +-- themes: ThemesConfig
 +-- security: CmsSecurityConfig
 +-- commerce: ?CommerceConfig       (null = disabled)
 +-- liveCss: LiveCssConfig
 +-- import: ImportConfig
```

Every sub-config uses the same pattern: a `readonly` class with a `fromArray()` static factory. All values have sensible defaults; regulated deployments should enable `editorialWorkflow`, `eventSourcing`, and `atomicSnapshots`.

## Integration Map

### Pulsar Core Dependencies

| Pulsar Module | CMS Usage                                                                   |
| ------------- | --------------------------------------------------------------------------- |
| `Container`   | `ContainerInterface` for DI; `instance()` for explicit binding              |
| `Config`      | `ConfigManagerInterface` to locate `config/cms.php`                         |
| `Routing`     | `RouterInterface` for HTTP route registration                               |
| `Event`       | `EventDispatcherInterface` for domain event dispatch                        |
| `Database`    | `ConnectionInterface` for all persistence                                   |
| `Cache`       | `TaggedCacheInterface` for settings warming                                 |
| `Auth`        | `RoleRegistryInterface` for permission registration                         |
| `Audit`       | `AuditLoggerInterface` for security and compliance audit trails             |
| `Security`    | `MasterKey` for cryptographic key derivation (2FA, digital delivery tokens) |
| `Http`        | `UploadedFile` for media uploads                                            |
| `Pagination`  | `PaginationResult` for paginated queries                                    |

### CMS Extension Integrations

- **Admin (Pulsar Admin)** -- Admin routes are registered under `/admin/cms/` and integrate with the admin panel navigation.
- **Studio (Pulsar Studio)** -- A dedicated `CmsStudioModule` provides audit panel, cache inspector, media queue, and SEO health panels.
- **ORM** -- Raw database queries via `ConnectionInterface` (no ORM abstraction layer).
- **Payments** -- Commerce subsystem integrates with `PaymentGateway` for checkout and webhook handling.

## Data Flow: Content Lifecycle

```
  [Author creates content]
         |
         v
  Content::create() --> Draft
         |
         v
  +-- Standard Mode --------+-- Editorial Mode --------+
  |  Draft --> Published     |  Draft --> InReview       |
  |  Draft --> Scheduled     |  InReview --> Approved    |
  +-------------------------+  Approved --> Published    |
                              |  InReview --> Draft (reject)
                              +-------------------------+
         |
         v
  Published --> Archived --> Draft (restore)
```

Each transition is validated by `PublishingStatus::canTransitionTo()`, which encodes the state machine rules for both standard and editorial workflow modes.

### Content Creation to Rendering

1. **Creation** -- Admin creates a `Content` entity (aggregate root) and one or more `ContentTranslation` records (per-locale title, slug, body, SEO metadata).
2. **Field values** -- Custom field values are stored via `FieldRegistryRepositoryInterface` using typed columns (`value_string`, `value_int`, `value_float`, `value_bool`, `value_datetime`, `value_json`).
3. **Revision tracking** -- Every save creates a `ContentRevision` with diffable snapshots.
4. **Event sourcing** (optional) -- When enabled, each mutation appends a `ContentEvent` to the event store.
5. **Publishing** -- Content transitions to `Published` status. Atomic snapshots (optional) capture all-locale state.
6. **Cache** -- Tag-based cache invalidation ensures published content is served from cache with instant invalidation on updates.
7. **Rendering** -- Public requests hit the `ContentController`, which resolves content by locale and path, applies the active theme's template, and returns the rendered page.
8. **Cache invalidation** -- On content update, cache tags associated with the content ID and content type are invalidated.

### Cache Invalidation Flow

```
  Content updated/published
         |
         v
  EventDispatcher fires ContentUpdated/ContentPublished
         |
         v
  Cache tags invalidated: ["content:{id}", "content-type:{type}"]
         |
         v
  Next request rebuilds from database
```

## Module Dependency Graph

```
  Config ----+
             |
  Content <--+-- Taxonomy
     |  |         |
     |  +-- Navigation (breadcrumbs depend on Content hierarchy)
     |  |
     |  +-- Workflow (locks and reviews reference Content)
     |  |
     |  +-- EventStore (events reference Content mutations)
     |  |
     |  +-- FieldRegistry (custom fields attach to Content types)
     |  |
     |  +-- Comments (comments attach to Content items)
     |
     +-- Media (content body references media assets)
     |
     +-- Search (full-text index over Content translations)
     |
     +-- SEO (meta tags, sitemaps, structured data from Content)
     |
     +-- Themes (template resolution for Content rendering)
     |
     +-- Plugins (register custom content types, hooks, blocks)
     |
     +-- LiveCss (CSS overrides applied on top of active theme)
     |
     +-- Commerce (products are a specialized content type)
     |
     +-- Users (authors and editors of Content)
     |
     +-- Settings (site-wide configuration values)
     |
     +-- Tools (import/export/backup of Content and related data)
     |
     +-- Dashboard (widgets display Content statistics)
```

## Security Architecture

### Permission Model

The CMS defines 9 hierarchical roles with 50+ granular permissions:

| Role                   | Inherits From | Key Permissions                                                                             |
| ---------------------- | ------------- | ------------------------------------------------------------------------------------------- |
| `cms.viewer`           | --            | (no specific permissions)                                                                   |
| `cms.contributor`      | viewer        | View/create content, submit for review, view taxonomy/menus/media/comments                  |
| `cms.reviewer`         | contributor   | Approve content                                                                             |
| `cms.editor`           | reviewer      | Edit/publish/archive/delete content, force unlock, manage taxonomy/menus, moderate comments |
| `cms.media_manager`    | viewer        | Upload/delete media                                                                         |
| `cms.seo_manager`      | viewer        | View/manage SEO settings                                                                    |
| `cms.shop_manager`     | viewer        | Full commerce management (products, orders, promotions, invoices, digital assets)           |
| `cms.analytics_viewer` | viewer        | View search analytics                                                                       |
| `cms.admin`            | all roles     | Full access including themes, plugins, settings, users, import/export, backups, live CSS    |

### Data Classification

Content items carry a `DataClassification` level that controls access and audit behavior:

- `Public` -- No restrictions
- `Internal` -- Organization-internal only
- `Confidential` -- Restricted access with enhanced audit logging
- `PII` -- Personal data subject to GDPR/privacy regulations

### Plugin Sandboxing

Plugins operate within strict guardrails:

- **Scoped container** -- `ScopedContainerProxy` limits which services a plugin can resolve (only `LoggerInterface`, `TaggedCacheInterface`, `EventDispatcherInterface`, `ContentRepositoryInterface`, `MediaRepositoryInterface`, `SettingsServiceInterface`).
- **Memory limits** -- Hook callbacks are limited to 32 MB of memory consumption.
- **Circuit breaker** -- After 10 failures within a 5-minute window, a plugin is automatically disabled.
- **Output buffering** -- Hook callbacks cannot produce output (captured and discarded).
- **Provenance verification** -- Plugin packages can be required to have valid Ed25519 signatures.

### Theme Sandboxing

Themes have similar protections:

- **Archive extraction** -- Zip Slip protection during installation.
- **Manifest validation** -- Required fields and format constraints are enforced.
- **Provenance verification** -- Theme packages can be required to have valid Ed25519 signatures.
- **File limits** -- Maximum archive size (50 MB) and file count (10,000).

## Multi-Tenancy

All entities support optional multi-tenancy via a nullable `tenantId` field (UUIDv7). When tenancy is disabled, `tenantId` is `null` and all data is shared. When enabled, queries are scoped by tenant ID at the repository level.

## Observability

The CMS integrates with Pulsar's observability stack:

- **Audit logging** -- All security-sensitive operations (content publish, plugin install, user actions, GDPR operations) are logged via `AuditLoggerInterface` with structured context.
- **Application logging** -- `LoggerInterface` (PSR-3) for operational logging throughout all service stacks.
- **Event dispatch** -- Domain events (`ContentCreated`, `ContentPublished`, `ThemeInstalled`, `PluginEnabled`, `OrderCreated`, etc.) enable event-driven architectures and monitoring integration.
