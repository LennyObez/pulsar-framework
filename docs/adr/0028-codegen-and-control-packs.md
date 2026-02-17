# ADR-0028: Codegen Engine & Control Packs

- **Status**: Accepted
- **Date**: 2026-03-03
- **Plan**: RC11-21

## Context

Pulsar targets regulated, mission-critical domains (banking, healthcare, legal). Developers in these domains need to scaffold entities, repositories, migrations, validation rules, API resources, admin panels, and test factories from entity definitions. They also need domain-specific starter kits that pre-configure compliance-relevant infrastructure without overstating what the framework guarantees.

Existing PHP frameworks offer code generation (Symfony MakerBundle, Laravel generators) but lack:

1. **Security-first generation**: no directory traversal protection, no overwrite safeguards, no path allowlisting.
2. **Domain-specific starter kits**: no starter templates for regulated domains with honest compliance framing.
3. **Safe template rendering**: many use eval() or unconstrained string interpolation for code generation.
4. **Schema-aware generation**: few offer diff-based migration generation from entity snapshots.

Pulsar needs a codegen engine that is safe, deterministic, and extensible, paired with a control pack system for regulated domain scaffolding.

## Decision drivers

1. **Safety**: Generated code must never execute arbitrary expressions. Output paths must be confined to an allowlist.
2. **Honesty**: Control packs must clearly distinguish scaffolding from compliance certification (Finding C from the CMS architectural audit).
3. **Non-destructiveness**: Code generation must not silently overwrite existing files. Users must opt in to overwrites.
4. **Determinism**: Same inputs must produce identical outputs (no timestamps, no random values in generated code).
5. **Extensibility**: Third-party generators must be possible via the `GeneratorInterface` contract.

## Decision

### Safe template rendering (no eval)

The `TemplateRenderer` uses `{{variable}}` placeholder substitution via `str_replace()`. Case transformation filters (`PascalCase`, `camelCase`, `snake_case`, `kebab-case`) are supported via a whitelist of known filter names dispatched through a `match` expression. Unknown filters throw `InvalidArgumentException`.

No `eval()`, no `preg_replace_callback()` with user-controlled replacement strings, no arbitrary code execution paths. Templates are inert strings with named holes.

### Path validation with directory allowlist

`PathValidator` enforces a configurable directory allowlist (default: `src/`, `tests/`, `config/`, `database/migrations/`). All generated file paths are validated before writing. Directory traversal (`..`) is rejected unconditionally. Paths are normalized to forward slashes for cross-platform consistency.

### Non-destructive generation (no-overwrite default)

`OverwritePolicy` is an enum with three modes:

- **Skip** (default): If the file exists, skip it silently.
- **Force**: Overwrite unconditionally.
- **Fail**: Throw an exception if any target file already exists.

`GeneratedFileSet` detects conflicts (files that already exist on disk) and `AbstractGenerator` enforces the policy. The `ConflictReporter` provides a deterministic, sorted list of conflicting paths for user review. `GeneratorConfig.force` overrides per-file policies when the user explicitly requests it.

### Identifier normalization

`IdentifierNormalizer` converts database identifiers (table names, column names) to valid PHP identifiers. It handles:

- CamelCase boundary detection and word splitting
- Underscore, hyphen, and mixed-separator normalization
- PHP reserved word suffixing (`class` becomes `ClassEntity`, `match` becomes `MatchEntity`)
- SQL reserved word suffixing (prevents collision with query builder method names)
- Leading non-letter character handling (prefix with `Entity` or `field`)

### Schema snapshots and diff-based migration

`SchemaSnapshot` captures entity definitions at a point in time. `SchemaDiff` compares two snapshots and produces `SchemaOperation` entries (add column, remove column, rename, change type). `MigrationGenerator` transforms these operations into migration files. The snapshot store persists snapshots as JSON files for version tracking.

### Generator contract

All generators implement `GeneratorInterface` with a single method:

```php
public function generate(EntityDefinition $entity, GeneratorConfig $config): GeneratedFileSet;
```

`AbstractGenerator` provides the shared pipeline: generate files, validate paths, detect conflicts, enforce overwrite policies. Concrete generators (Repository, Migration, Validation, Form, ApiResource, AdminResource, TestFactory, Policy) implement `doGenerate()`.

### Control pack system

Scaffolding packs are domain-specific starter kits stored in `resources/packs/{name}/`. Each pack contains:

- `pack.json`: Manifest DTO (`PackManifest`) with name, description, version, required Pulsar version, compliance presets, file mappings, and post-install commands.
- `config/`: Configuration stubs for the domain (encryption, audit, logging, etc.)
- `src/`: Entity stubs with `{{project_name}}`, `{{namespace}}`, `{{project_slug}}` placeholders.
- `tests/`: Test stubs for the entity scaffolds.
- `docs/setup-guide.md`: Getting started documentation.
- `SCAFFOLDING.md`: Scaffolding coverage report using "supports controls for" language.
- `NOT-CERTIFIED.md`: Prominent disclaimer that the pack is not a compliance certification.

`PackLoader` discovers and validates packs. `PackInstaller` copies and processes template files with safe variable substitution (str_replace, no eval). Integration via `pulsar new --pack={name}`.

### Compliance framing (Finding C)

All compliance-related language follows strict framing rules:

- Use "supports controls for", never "ensures compliance with" or "guarantees"
- NOT-CERTIFIED.md is mandatory and prominent in every pack
- SCAFFOLDING.md distinguishes framework scaffolding from organizational controls
- "Additional Steps Needed" section lists requirements for actual certification
- Pack descriptions are honest about what is a scaffold vs. what is production-ready

Five packs ship with the framework: banking (PCI-DSS/PSD2/DORA), healthcare (HIPAA/MDR), legal (document retention/audit), saas (tenant isolation/billing), and api (auth/rate limiting/versioning).

## Alternatives considered

### Twig/Blade for template rendering

Rejected. Full template engines introduce eval()-equivalent execution paths, add a dependency, and are overkill for variable substitution in generated code. The `{{variable|filter}}` syntax covers all code generation needs without executing arbitrary expressions.

### Allow generation anywhere on disk

Rejected. Unrestricted path generation enables directory traversal attacks. The allowlist approach is deny-by-default: only explicitly permitted directories are writable.

### Overwrite by default

Rejected. Silent overwriting destroys user customizations without warning. The skip-by-default policy respects existing work. Users who want to regenerate must explicitly pass `--force`.

### Certified compliance packs

Rejected. Claiming certification without a professional audit is legally and ethically irresponsible. Packs are scaffolds that support controls; certification is the operator's responsibility.

### Pack content as PHP classes

Rejected. Pack templates are scaffolds intended for the user's project, not framework code. They use `{{namespace}}` placeholders precisely because they will live outside the Pulsar namespace. Storing them as strings with placeholder substitution is the correct approach.

## Consequences

### Positive

- Code generation is safe against path traversal and arbitrary code execution
- Non-destructive defaults protect existing user code
- Deterministic output enables testing and reproducible builds
- Control packs provide immediate value for regulated domain projects
- Honest compliance framing protects both Pulsar and its users from false assurances

### Negative

- Template filter set is limited to four case transformations (sufficient for code generation; extensible if needed)
- Path allowlist must be updated if new output directories are needed
- Control pack templates are static strings, not dynamically composable (adequate for starter kits)

### Neutral

- `GeneratorInterface` contract is minimal by design; composition over inheritance for complex generators
- Five packs ship as defaults; additional packs can be added by placing directories in `resources/packs/`

## Security impact

- **PathValidator** eliminates directory traversal and arbitrary file write vulnerabilities in code generation
- **TemplateRenderer** eliminates code injection via template variables (no eval, no dynamic code execution)
- **OverwritePolicy** prevents accidental destruction of security-sensitive files (e.g., existing auth configurations)
- **Control packs** include configuration stubs for encryption-at-rest, audit logging, and access controls: but these are scaffolds, not production implementations

## Performance impact

None. Code generation is a development-time operation, not a runtime hot path. Template rendering uses simple `str_replace()`.

## Migration / rollback plan

**Adoption**: Add `src/Codegen/` to the project. Control packs are opt-in via `pulsar new --pack={name}`. No existing code is affected.

**Rollback**: Remove `src/Codegen/` and `resources/packs/`. No runtime code depends on either. The `--pack` option in `NewCommand` gracefully handles missing packs.

## Links

- ADR-0002: Modular monolith with vertical slices and ports/adapters (module structure for generated code)
- ADR-0009: Attribute-based public API surface (`#[Api]`/`#[Internal]` on codegen classes)
- CMS Architectural Audit, Finding C: Compliance framing requirements
