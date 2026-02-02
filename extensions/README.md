# Pulsar Extensions

This directory contains first-party extensions for the Pulsar Framework.

## What is an Extension?

An extension is a self-contained package that adds functionality to Pulsar. Extensions use the same public API available to third-party packages.

## Extension Structure

Each extension follows this structure:

```
<extension-name>/
├─ pulsar.json     # Extension manifest (required)
├─ composer.json   # Composer package definition
├─ src/            # Extension source code
├─ config/         # Default configuration
├─ routes/         # Route definitions (optional)
├─ views/          # View templates (optional)
├─ migrations/     # Database migrations (optional)
└─ tests/          # Extension tests
```

## Extension Manifest (pulsar.json)

The `pulsar.json` file declares extension metadata and capabilities:

```json
{
  "name": "pulsar/example-extension",
  "version": "1.0.0",
  "description": "Example extension description",
  "pulsar": {
    "min_version": "1.0.0",
    "max_version": "2.0.0"
  },
  "provides": {
    "services": ["ExampleService"],
    "commands": ["example:run"],
    "routes": true,
    "middleware": ["ExampleMiddleware"]
  },
  "requires": {
    "extensions": ["pulsar/other-extension"]
  }
}
```

## Extension Lifecycle

1. **Discover**: Pulsar scans for `pulsar.json` manifests
2. **Validate**: Check version compatibility and dependencies
3. **Register**: Extension registers its services, routes, commands
4. **Boot**: Extension performs initialization

## Creating an Extension

1. Create a new directory under `extensions/`
2. Add `pulsar.json` manifest
3. Add `composer.json` for autoloading
4. Implement the extension service provider
5. Register capabilities

## First-Party Extensions

First-party extensions maintained by the Pulsar team will be added here as the framework matures. These extensions serve as:

- Reference implementations
- Common functionality
- Demonstration of best practices

## Guidelines

- Extensions must be self-contained
- Use dependency injection, not service locators
- Provide sensible defaults
- Document all configuration options
- Include tests
