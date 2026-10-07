# Pulsar documentation

Every page under `docs/` except the decision records, which have
[their own index](adr/README.md).

This page exists because twenty-six pages here had no inbound link from anywhere in the
repository — not from another page, not from the README, not from a source comment. They
were reachable only by knowing the filename. A documentation tree with no index does not
have twenty-six unfinished pages; it has twenty-six pages nobody can find, which is the
same as not having written them.

`DocsIndexTest` (`tests/Unit/Documentation/DocsIndexTest.php`) keeps it that way: a page
added under `docs/` and not listed here fails the build, as does a link on this page that
resolves to nothing. Adding a page therefore costs one line here, and the alternative —
adding it silently — is not available.

## Start here

The path from an empty directory to a running application.

- [Getting started](getting-started.md) — This guide walks you through installing Pulsar, creating a project, and building your first routes and controllers.
- [Installation guide](install.md) — Requirements, the composer install, and the extensions PHP must have loaded.
- [Bootstrap](bootstrap.md) — How to wire the Pulsar kernel in an HTTP entry point.
- [Configuration](configuration.md) — Pulsar provides a typed configuration system that loads settings from PHP files, environment variables, and optional `.env` files.
- [Repository structure](repository-structure.md) — This page names the **top-level directories** and what each is for.
- [PHP feature matrix](php-feature-matrix.md) — Which PHP 8.5 features the framework uses, and what each one buys.
- [Upgrade guide: 0.x to 1.0.0-rc.12](upgrade.md) — This guide covers the migration path from Pulsar 0.x (pre-alpha/alpha) to 1.0.0-rc.12.

## Working in a project

The tools you reach for daily.

- [CLI reference](cli-reference.md) — Pulsar provides a command-line interface through the `bin/pulsar` entry point.
- [DX commands reference](dx-commands.md) — Scaffolding commands that emit boundary-compliant modules, config DTOs, wiring and test stubs.
- [Interactive REPL (`pulsar shell`)](repl.md) — An interactive PsySH shell with the container, configuration and framework helpers already loaded.
- [UI playground](playground.md) — The Pulsar UI Playground is a local development tool for real-time CSS customization and theming.
- [Schema-Driven Code Generation](codegen.md) — Pulsar's codegen engine produces complete, production-quality PHP from a single entity definition.
- [Scaffolding packs](scaffolding-packs.md) — Scaffolding packs are domain-specific starter kits for regulated industries.
- [Testing](testing.md) — PHPUnit for unit, integration and E2E; Infection for mutation; PHPBench for budgets; and the gate order.
- [Migrations](migrations.md) — Pulsar migrations use timestamped PHP files containing anonymous classes.

## HTTP

Requests, responses, and everything between them.

- [HTTP layer](http.md) — The PSR-7 implementation, header validation, and the request lifecycle.
- [Middleware](middleware.md) — Pulsar uses a pipeline-based middleware system.
- [Route Model Binding](route-model-binding.md) — Route model binding turns a route parameter into the domain object it names.
- [Request validation](validation.md) — Pulsar provides a typed, rule-based validation system for request data.
- [Form extension](forms.md) — The Form extension provides a comprehensive, security-first form building system for Pulsar applications.
- [Asset serving](assets.md) — How to serve CSS, JavaScript, images, and other static files from a Pulsar project.
- [API Tooling](api-tooling.md) — Pulsar provides a complete API layer for building secure, documented, and standards-compliant REST APIs.
- [gRPC Extension](grpc.md) — Pulsar's gRPC extension provides first-class support for building gRPC services with PHP 8.5+.

## Views, UI and language

What the browser receives.

- [Template engine](templating.md) — Pulsar ships a compile-to-PHP template engine for trusted templates and a sandboxed AST interpreter for untrusted (user-provided) templates.
- [Design system](design-system.md) — Pulsar UI is a zero-dependency CSS framework providing design tokens, a responsive grid, utility classes, and a component library.
- [Internationalization (i18n)](i18n.md) — Translation, ICU MessageFormat, locale negotiation, and number, date and currency formatting.
- [Accessibility](accessibility.md) — The `pulsar/accessibility` extension provides composable building blocks for WCAG 2.1 AA accessibility in Pulsar applications.

## Data

Storage, mapping and the layers over them.

- [Database layer](database.md) — Pulsar provides a thin, explicit database abstraction layer built on PDO.
- [ORM extension](orm.md) — Opt-in entity mapping and persistence layer built on top of Pulsar's Database Layer.
- [Framework caching & boot profiling](caching.md) — The HMAC-signed compiled payload of config, routes and bindings, and how to profile boot.
- [Import system](import.md) — Pulsar provides a unified, idempotent, multi-mode import pipeline for CMS content, taxonomy, menu, and extension data.

## Architecture

How the framework is put together, and the rules that keep it that way.

- [Pulsar architecture](architecture.md) — The framework core is intentionally minimal.
- [Modular monolith architecture](modular-monolith.md) — Pulsar follows a **Modular Monolith** architecture combining **Vertical Slices** and **Ports/Adapters** patterns.
- [Boundary enforcement](boundary-enforcement.md) — Automated enforcement of module boundaries to prevent architectural erosion.
- [Async model](async-model.md) — Why the core is synchronous, and where fibers are allowed (ADR-0005).
- [Multi-runtime support](runtime.md) — Pulsar supports four HTTP runtimes with a unified interface.
- [Superglobal isolation in persistent runtimes](architecture/superglobal-isolation.md) — How a persistent worker keeps one request out of the next.
- [Event system](events.md) — PSR-14 dispatch with envelopes, storm protection, module scope tracking and a compiled listener map.
- [Workflow & Saga Orchestration](workflow-saga.md) — Pulsar provides two complementary modules for managing complex, multi-step processes in regulated domains.
- [Service Discovery & Config Center](service-discovery.md) — Pulsar provides a built-in service discovery and centralized configuration system for distributed deployments.
- [Multi-tenancy](multi-tenancy.md) — Tenant resolution and the isolation guarantees that come with each strategy.
- [Self-healing and resilience](self-healing.md) — Pulsar provides a comprehensive resilience layer for building fault-tolerant, mission-critical applications.
- [Backup and restore](backup.md) — One sealed, tamper-evident archive per run; what is in it, what is deliberately not, and which half of a recovery is yours.
- [Feature flags](feature-flags.md) — Controlled rollouts with an audit trail, for environments where a flag flip is a change.
- [Public API reference](public-api.md) — Pulsar uses two PHP attributes to classify every type in the framework.

## Extensions

The extension lifecycle, and the extensions that ship with the framework.

- [Extension system guide](extensions.md) — Pulsar's extension system provides modular, manifest-driven extensibility with a deterministic four-phase lifecycle.
- [Extension versioning](extension-versioning.md) — Pulsar extensions follow semantic versioning.
- [Contributing extensions](contributing/extensions.md) — This guide covers everything you need to create, test, and publish a Pulsar extension.
- [Studio admin panel](admin.md) — ORM-agnostic CRUD admin panel built as a Pulsar extension.
- [Pulsar studio](studio.md) — Pulsar Studio is a built-in observability and debugging subsystem for local development and staging environments.
- [AI Tooling - MCP Server Extension](mcp-server.md) — Pulsar ships an optional MCP (Model Context Protocol) server extension that exposes framework metadata and developer tools to AI assistants.
- [Health status](health-status.md) — Records health-check snapshots to a durable store, detects incidents, and serves a status page.
- [Notifications](notification.md) — Pulsar's notification module provides multi-channel delivery with consent tracking, legal basis enforcement, and preference management.
- [Mail](mail.md) — Pulsar's mail module provides a unified API for sending email through multiple transport backends.
- [Payments extension](payments.md) — Vendor-agnostic payment processing for Pulsar applications.
- [Scheduler](scheduler.md) — Pulsar ships a job scheduler for running recurring tasks on a cron-based schedule.

## AI

Calling a model from the framework, and the controls around the call.

- [The AI egress boundary](ai-egress.md) — `GuardedAiClient`, an opt-in decorator you compose over a provider: a payload is refused unless its host is on your allow-list and its sensitive-data check passes, and a check that cannot run refuses too.
- [Inference auditing](ai-inference-auditing.md) — Every model call, failed ones included, writes one HMAC-chained audit entry, and so does every refusal when `GuardedAiClient` is composed under it. Prompts and completions are kept as keyed digests (ADR-0080).
- [Streaming AI responses](ai-streaming.md) — Reading a model's answer as it is generated, and why a stream that stops early is refused rather than returned.

## Security

Controls the framework provides, and how to configure them.

- [Security baseline](security-baseline.md) — Pulsar ships secure defaults for session management, CSRF protection and security headers.
- [Authentication](authentication.md) — Guard-based authentication: lazy identity resolution, session and token guards, password hashing, 2FA.
- [Authorization](authorization.md) — Pulsar provides a hybrid RBAC + ABAC authorization system through the Gate.
- [Session Management](session-management.md) — Pluggable handlers, encryption at rest, session validators, flash messages and concurrent-session limits.
- [OAuth2/OIDC Authorization Server](oauth2-oidc.md) — The OAuth2 authorization server and OpenID Connect provider in the bundled `pulsar/auth` extension.
- [OAuth2 authorization model](oauth2-authorization-model.md) — This document explains how Pulsar derives an authorisation decision from an OAuth2 access token.
- [WebAuthn/Passkeys](webauthn.md) — Pulsar provides FIDO2-compliant passwordless authentication with passkey support through the bundled **`pulsar/auth`** extension.
- [Anti-Spam](anti-spam.md) — A composable pipeline of independent checks: honeypot, duplicate detection, link analysis, time trap, reputation.
- [Audit logging](audit-logging.md) — Pulsar provides a tamper-evident audit logging subsystem separate from general application logging.
- [Key rotation runbook](key-rotation.md) — How every subkey derives from `PULSAR_MASTER_KEY`, and the runbook for rotating it.
- [File integrity](integrity.md) — Pulsar includes a file integrity verification system that detects unauthorized filesystem changes in deployed applications.
- [Deterministic build pipeline](build-pipeline.md) — Pulsar's build pipeline compiles all production artifacts into immutable, content-addressed files.
- [PSD2 / eIDAS certificate validation](psd2.md) — Validating the QWAC and QSEAL certificates that identify a payment service provider under PSD2.

## Security analysis

Threat models and control matrices. Assessment material, not how-to guides.

- [OWASP ASVS Level 2 — Pulsar control matrix](security/asvs-l2-matrix.md) — Every OWASP ASVS L2 clause against the control that meets it, with file:line and test.
- [Session Management - Threat Model](security/session-threat-model.md) — Threat model for session management: what is stored, and what an attacker gets.
- [Compliance Event System - Threat Model](security/compliance-threat-model.md) — Threat model for the compliance event system.
- [Zero-Trust Architecture - Threat Model](security/zero-trust-threat-model.md) — Threat model for the zero-trust claim, policy and device-trust surfaces.
- [Zero-Trust Architecture - Regulatory Compliance Mapping](security/zero-trust-compliance-mapping.md) — The zero-trust architecture mapped clause by clause to the frameworks that ask for it.
- [Dependency security policy](security/dependency-policy.md) — How a dependency advisory is triaged, and the deadline attached to each severity.
- [Archived security documents](security/archive/README.md) — Dated security snapshots kept for the record, superseded by the pages above.
- [OAuth2/OIDC + WebAuthn Threat Model](security/archive/oauth2-webauthn-threat-model.md) — Archived: threat model for the pre-merge OAuth2 and WebAuthn packages.
- [OAuth2/OIDC + WebAuthn Compliance Gap Analysis](security/archive/oauth2-webauthn-compliance-review.md) — Archived: PSD2/SCA gap analysis against those same packages.

## Compliance

Regulatory framework mappings and the machinery that reports on them.

- [Compliance matrix](compliance.md) — Capability-by-capability status, and the frameworks each capability serves.
- [Common Control Framework (CCF)](compliance-ccf.md) — Pulsar declares regulatory controls, and computes each one's outcome from the deployment it is run against.
- [Compliance events](compliance-events.md) — Pulsar's compliance event system provides a structured, auditable event model for regulated domains.

## Running it

Deployment targets, and what to watch once it is deployed.

- [Deployment guide](deployment.md) — The pre-deploy checklist and what each `pulsar deploy:check` gate refuses.
- [Persistent Runtime Deployment](deployment/persistent.md) — This guide covers deploying a Pulsar application with the built-in persistent HTTP runtime.
- [FrankenPHP Deployment](deployment/frankenphp.md) — This guide covers deploying a Pulsar application with FrankenPHP in worker mode.
- [RoadRunner Deployment](deployment/roadrunner.md) — This guide covers deploying a Pulsar application with RoadRunner.
- [Health Monitoring](deployment/health-monitoring.md) — Health checks, monitoring and observability for applications on a persistent runtime.
- [Observability](observability.md) — Metrics, tracing, health endpoints and the correlation id that ties them together.
- [OpenTelemetry](opentelemetry.md) — Pulsar's OpenTelemetry extension exports traces, metrics, and logs to any OTLP-compatible collector.
- [Logging](logging.md) — Pulsar provides a PSR-3 compliant structured logging system.
- [Error handling](error-handling.md) — Pulsar provides centralized exception handling with separate development and production renderers.
- [Performance benchmarking](performance.md) — Pulsar includes a benchmark harness that measures the performance impact of OPcache, JIT, and preloading configurations.

## Project governance

Release policy, support windows, and the process around changes.

- [PRD — Pulsar Framework 1.0.0](prd-1.0.0.md) — Pulsar 1.0.0 is the first stable release of a PHP 8.5+ HMVC framework for regulated, mission-critical domains.
- [Long-term support plan](lts-plan.md) — This document describes Pulsar's release cadence, support windows, and what each support tier includes.
- [Deprecation policy](deprecation-policy.md) — Pulsar follows semantic versioning for all public API surfaces.
- [Dependabot security PR policy and SLA](dependabot-sla.md) — This document records the project's policy for handling Dependabot security PRs.
- [CI pipeline and ADR governance](contributing/ci-and-adr.md) — The CI workflow (`.github/workflows/ci.yml`) runs on every push and pull request.
