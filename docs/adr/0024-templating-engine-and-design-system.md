# ADR-0024: Templating Engine and Design System

## Status

Accepted

## Context

Pulsar targets regulated, mission-critical domains (banking, healthcare, legal) where both the Admin panel and Studio extension require server-rendered UIs. Without a framework-level template engine, these UIs depend on ad-hoc string concatenation or external templating libraries, creating inconsistency and security risk. Additionally, there is no unified visual language - each UI module styles components independently, leading to drift and accessibility gaps.

Key constraints:

- Templates from developers (trusted) and user-provided templates (untrusted, e.g., tenant-customizable emails) must coexist but with fundamentally different security models.
- The escaping model must be context-aware (HTML, URL, attribute, JS, CSS) to prevent XSS in all output contexts.
- Regulated environments require audit trails for any inline PHP execution in templates.
- All UI components must meet WCAG 2.1 AA accessibility standards.
- Zero external CSS/JS dependencies - the framework must not impose a third-party design framework on applications.

## Decision Drivers

1. **Security**: Untrusted user templates must never execute arbitrary PHP code, even if the trusted template engine uses compile-to-PHP for performance.
2. **Performance**: Trusted templates must compile to native PHP with sub-5ms compilation time and zero runtime overhead.
3. **Compliance**: `@php` directive usage in templates must produce auditable events for SOC 2/HIPAA trail requirements.
4. **Consistency**: A single design token system must govern all Pulsar UIs (Admin, Studio, user portals) to prevent visual drift.
5. **Developer experience**: Template syntax should be familiar (Blade-like) with strong conventions, and theming should be accessible via a live playground.

## Decision

### Template Engine: Dual Execution Model

Implement a compile-to-PHP engine for **trusted templates** and a restricted AST interpreter for **untrusted templates**:

- **Trusted path** (`TemplateCompiler`): Compiles `.pulsar.php` templates to cached PHP files. Supports the full directive set (`@if`, `@foreach`, `@for`, `@while`, `@switch`, `@extends`, `@section`, `@yield`, `@include`, `@component`, `@slot`, `@auth`, `@guest`, `@can`, `@csrf`, `@method`, `@i18n`, `@php`). Output is deterministic and cacheable. Build-time compilation via `view:compile` produces deployable artifacts.

- **Untrusted path** (`SandboxEngine`): Parses templates into an AST and interprets them without generating PHP. Supports only safe directives (`@if`, `@foreach`, `@include` from an allowlist, `@i18n`, variable interpolation). Enforces deterministic resource bounds: step counter, loop iteration limit, output size cap, and periodic wall-clock checks. Raw output (`{!! !!}`) is blocked. `@include` uses registered template IDs rather than file paths.

### Escaping Model

Default HTML escaping on all `{{ }}` output via `htmlspecialchars()` with `ENT_QUOTES | ENT_SUBSTITUTE`. Context-aware helpers (`url()`, `attr()`, `js()`, `css()`) for non-HTML output contexts. The `url()` helper normalizes invisible Unicode characters before blocking dangerous URI schemes (`javascript:`, `data:`, `vbscript:`).

### @php Directive Policy

Disabled by default in production (`ViewConfig::phpDirectiveAllowed = false`). When disabled, the compiler rejects `@php` blocks at compile time. When enabled, each usage emits a compliance-grade audit event via `AuditLoggerInterface`.

### Design System: Pulsar UI

A zero-dependency CSS framework using CSS custom properties (design tokens) as the single source of truth:

- Design tokens define colors, spacing, typography, borders, shadows, transitions, and z-index
- 12-column responsive grid with both flexbox and CSS Grid options
- Dark/light theme support via `prefers-color-scheme` and explicit `data-theme` attribute
- RTL support via CSS logical properties throughout
- Component library organized by category: layout, navigation, forms, data display, feedback, actions, typography, media, and miscellaneous
- Print stylesheet for clean printed output

### Design Token Governance

Tokens and component CSS APIs are versioned following semver. All components reference tokens, never raw values. CI verifies no drift between compiled tokens and CSS output.

### Playground

A local dev server (`pulsar playground:serve`) providing a component catalog with a built-in CSS editor, real-time preview, and theme file management. Dev-only (disabled in production). Custom-built with vanilla HTML/CSS/JS - no external editor dependencies.

## Alternatives Considered

### Alternative A: Adopt Twig

Twig is mature and well-documented, but it adds an external dependency, uses a custom syntax unfamiliar to PHP developers, and does not natively support the dual trusted/untrusted execution model required for regulated environments. Integrating audit logging and deterministic sandbox bounds into Twig would require extensive modifications.

### Alternative B: Adopt Blade Directly

Blade is tightly coupled to Laravel and compiles all templates to PHP, including those from untrusted sources. Extracting Blade as a standalone library would bring transitive Laravel dependencies. The untrusted template sandboxing requirement (never compile to PHP) is fundamentally incompatible with Blade's architecture.

### Alternative C: Use Tailwind CSS

Tailwind is an excellent utility-first framework but introduces a Node.js build dependency, requires PostCSS configuration, and generates large CSS files unless purged. For a PHP framework targeting zero-dependency deployments and regulated environments, owning the CSS layer provides tighter control over versioning, token governance, and accessibility guarantees.

### Alternative D: Plates (Native PHP Templates)

Plates uses native PHP files as templates (no compilation step). While simple, it offers no directive syntax, no built-in escaping model, no template inheritance, and no mechanism for restricting untrusted template execution. The security boundary between trusted and untrusted templates would need to be built from scratch.

## Consequences

### Positive

- Complete security isolation between trusted and untrusted templates with different execution models
- Context-aware escaping prevents XSS across all output contexts (HTML, URL, attribute, JS, CSS)
- Compliance-grade audit trail for `@php` usage satisfies regulated-industry requirements
- Zero external dependencies for both the template engine and the CSS framework
- Familiar directive syntax lowers the learning curve for developers coming from Blade/Twig
- Design tokens as single source of truth prevent visual drift across Admin, Studio, and user portals
- Live playground accelerates theme development with instant feedback
- Build-time compilation eliminates runtime overhead in production

### Negative

- Custom template engine requires ongoing maintenance vs. using an established library
- Custom CSS framework requires documentation and learning investment vs. adopting a well-known framework
- Dual execution model (compiler + AST interpreter) increases the codebase and test surface
- The sandbox AST interpreter is inherently slower than compiled PHP for untrusted templates (but this is an intentional security tradeoff)

### Neutral

- The `.pulsar.php` template extension distinguishes Pulsar templates from plain PHP files
- The playground is dev-only and adds no production footprint
- Design token versioning follows the same semver discipline as the rest of the framework

## Security Impact

- **Untrusted template sandboxing**: User-provided templates cannot execute arbitrary PHP, access the filesystem, or perform function calls outside an explicit allowlist. Resource bounds prevent denial-of-service.
- **Context-aware escaping**: Default HTML escaping on all output with dedicated helpers for URL, attribute, JS, and CSS contexts. The URL escaper blocks `javascript:`, `data:`, and `vbscript:` schemes after normalizing invisible Unicode characters.
- **Raw output restriction**: `{!! !!}` is unavailable in untrusted mode. All untrusted output is auto-escaped.
- **@php audit logging**: When enabled, produces compliance-grade events suitable for SOC 2 / HIPAA audit trails.
- **Playground isolation**: Dev server is disabled in production. CSRF protection on write endpoints. Path traversal prevention on file operations.

## Performance Impact

- Trusted template compilation target: < 5ms per template
- Compiled templates execute as native PHP `include` with zero framework overhead
- Deterministic cache invalidation via content hash avoids unnecessary recompilation
- Build-time compilation (`view:compile`) eliminates all runtime compilation in production
- Sandbox AST interpretation adds overhead for untrusted templates (intentional tradeoff for security isolation)

## Migration / Rollback Plan

**Adoption**: Add `config/view.php`, create template files in `resources/views/` with the `.pulsar.php` extension, inject `TemplateEngineInterface` in controllers. Existing raw PHP views continue to work alongside Pulsar templates.

**Rollback**: Remove `config/view.php` to disable the View module. The `ViewWiring` service wiring skips registration when no config is present. Replace template engine calls with direct PHP output.

## Links

- Plan: `.claude/plans/rc11-16-templating-design-system.md`
- ADR-0004: Extension-first architecture (extension lifecycle via `pulsar.json`)
- ADR-0009: Attribute-based public API surface (`#[Api]` / `#[Internal]`)
- ADR-0011: Typed readonly configuration DTOs (ViewConfig pattern)
