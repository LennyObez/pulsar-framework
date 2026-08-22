# Long-term support plan

This document describes Pulsar's release cadence, support windows, and what each support tier includes. The goal is to give teams in regulated industries a predictable timeline for planning upgrades.

## Release types

### Major releases (1.0, 2.0, 3.0)

Major releases introduce breaking changes to the public API. They follow the deprecation policy: anything removed in a major was deprecated in a prior minor release.

New major versions ship every 18 to 24 months. Each major release is accompanied by a migration guide covering every breaking change.

### Minor releases (1.1, 1.2, 1.3)

Minor releases add new features, improvements, and non-breaking deprecations. They ship roughly every 2 to 3 months. Minor releases never remove public API, only deprecate it.

### Patch releases (1.0.1, 1.0.2)

Patch releases contain bug fixes and security patches. They ship as needed, sometimes within days of a reported vulnerability. Patch releases never change the public API.

## Support windows

### LTS releases (x.0)

Every major release is an LTS release. The 1.0.x line receives:

- **Active support for 12 months** from the GA release date. During active support, the release receives bug fixes, performance improvements, and security patches.
- **Security-only support for an additional 24 months** after active support ends. During this window, only security fixes are backported. No new features, no non-security bug fixes.
- **Total support lifetime: 3 years** from the GA date.

For example, if 1.0.0 ships in Q2 2026:

| Period         | Window             | What you get              |
| -------------- | ------------------ | ------------------------- |
| Active support | Q2 2026 to Q2 2027 | Bug fixes, perf, security |
| Security-only  | Q2 2027 to Q2 2029 | Security patches only     |
| End of life    | After Q2 2029      | No further releases       |

### Minor releases

Each minor release (1.1, 1.2, etc.) receives security fixes for 6 months after the next minor release ships. Once 1.2 is released, version 1.1.x continues to get security patches for 6 more months, then reaches end of life.

This gives teams a comfortable upgrade window. You do not need to jump to the latest minor on release day, but you should plan to upgrade within 6 months.

## PHP version support

Pulsar follows PHP's own support timeline. The minimum PHP version required by each Pulsar release is locked at release time and never changes within that release line.

| Pulsar version | Minimum PHP | Rationale                                                                  |
| -------------- | ----------- | -------------------------------------------------------------------------- |
| 1.0.x          | 8.5         | Uses PHP 8.5 language features (pipe operator, clone-with, property hooks) |

When PHP 8.5 reaches end of life, Pulsar will drop it in the next major release, not in a minor or patch. Teams running Pulsar 1.0.x on PHP 8.5 can continue to do so for the full 3-year LTS window regardless of PHP's own EOL schedule.

Future major releases will adopt the latest stable PHP version at the time of release, giving the community a clear signal about when to plan PHP upgrades.

## What "security fixes only" means

During the security-only window, the Pulsar team will:

- Backport fixes for confirmed security vulnerabilities (CVEs, disclosed advisories).
- Update cryptographic defaults if a cipher or hash algorithm is found to be weak.
- Patch dependency vulnerabilities where the fix is a drop-in update.

The team will not:

- Add new features or public API.
- Fix non-security bugs, even if they are annoying.
- Accept feature pull requests targeting the LTS branch.
- Update minimum PHP or extension requirements.

If a security fix requires a public API change, the team will find an alternative approach that preserves backward compatibility. In the rare case where this is impossible, the fix will be documented as a necessary security exception.

## End-of-life process

When a release line reaches end of life:

1. A final patch is released with a notice in the changelog.
2. The branch is archived (read-only, no further merges).
3. The documentation site marks the version as EOL.
4. Security reports against EOL versions will be acknowledged but not fixed. The response will point to the supported upgrade path.

## Overlapping support windows

Major releases are timed so that their active support windows overlap. When 2.0 ships, 1.0.x will still be in its security-only phase. This gives teams running 1.0.x at least 12 months of continued security coverage while they plan the migration to 2.0.

```
1.0.x  ████████████░░░░░░░░░░░░░░░░░░░░░░░░░  (active)  ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓  (security-only)
2.0.x                          ████████████░░░░░░░░░░░░░░  (active)  ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓
                               ^
                               2.0 GA ships while 1.0 is still in security-only support
```

## Upgrade recommendations

For teams in regulated environments, the recommended strategy is:

1. Stay on the current LTS for production workloads.
2. Test against the latest minor release in staging environments.
3. Begin migration planning when the next major enters release candidate phase.
4. Complete the migration before the current LTS enters its final year of security-only support.

This approach gives you the stability of an LTS release while keeping the migration effort manageable.
