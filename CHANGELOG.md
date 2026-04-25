# Changelog

All notable changes to Pulsar Framework are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial workspace foundation with 15 crates scaffold
- Cargo workspace configuration with Rust 2024 edition, pinned to 1.95.0
- Meta-files: README, LICENSE (Apache-2.0), SECURITY, CODE_OF_CONDUCT, CONTRIBUTING, CHANGELOG
- GitHub workflows for CI, nightly fuzz and mutation, security audit, benchmark regression, release publish
- Architecture decision records 0001 through 0005 (migration rationale, workspace layout, kernel design, template engine, frontend stack)
- Master plan document `docs/plan.md` covering Phase 0 through Phase 4
- Layered architecture diagram in `docs/architecture/layered.md`

### Notes

The Rust implementation replaces the former PHP implementation of Pulsar Framework. The final PHP release is tagged `v0.99.0-php-final` for historical reference.
