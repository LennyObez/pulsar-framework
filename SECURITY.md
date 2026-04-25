# Security Policy

## Supported Versions

Pulsar Framework is under active pre-1.0 development. Security patches are issued only for the latest minor release.

| Version | Security patches |
| ------- | ---------------- |
| 0.x (pre-alpha) | Only on `develop` and `main` branches; no backports to older alphas |
| 1.x (future GA) | Latest minor plus previous two for LTS (policy finalized at GA) |

## Reporting a Vulnerability

**Primary channel — GitHub Security Advisories (preferred):**

Open a private advisory at:

```
https://github.com/LennyObez/pulsar-framework/security/advisories/new
```

GitHub Security Advisories provide a private workspace for coordinated disclosure, patch development, and CVE assignment.

**Fallback channel — Email:**

If GitHub access is unavailable or the report must reach the maintainer by another path, send details to:

```
security@pulsar-framework.com
```

PGP encryption is supported. The public key fingerprint is published at `https://pulsar-framework.com/.well-known/security-contact.asc` (available once the domain is live).

## Disclosure Process

1. **Triage**: an acknowledgement is sent within **72 hours** of receipt.
2. **Assessment**: severity is evaluated per [CVSS 4.0](https://www.first.org/cvss/v4-0/). Severity levels follow the standard scale: Critical / High / Medium / Low / None.
3. **Remediation**: a fix is developed in a private branch. Target timelines:
   - Critical: patch within 14 days
   - High: patch within 30 days
   - Medium / Low: next scheduled minor release
4. **Coordinated disclosure**: the reporter is kept informed. A CVE is requested when applicable.
5. **Public advisory**: published once the patch is released, with credit to the reporter unless anonymity is requested.

## Scope

**In scope** (the framework itself):

- `crates/pulsar-*` under this repository
- Official Docker images
- Build artefacts published to crates.io under the `pulsar-*` namespace
- Default configuration examples shipped with the framework
- Documentation that, if wrong, could lead to an insecure deployment (e.g. outdated crypto primitives in the book)

**Out of scope**:

- Third-party extensions published outside the official `pulsar-*` namespace
- User-written application code built on Pulsar Framework
- Infrastructure of sites hosted under `pulsar-framework.com` unrelated to the framework release artefacts
- Denial-of-service reports that require more than a reasonable attacker budget (for example, brute-force floods larger than 10 Gbit/s)
- Reports that only apply to non-default, clearly documented insecure configurations

## Safe Harbor

Security researchers acting in good faith under this policy are granted safe harbor:

- No legal action will be pursued for vulnerability research conducted in accordance with this policy.
- Responsible disclosure is expected: a minimum embargo of **90 days** from first report, extendable by mutual agreement for complex fixes.
- Publishing proof-of-concept exploits before the embargo expires is considered a breach of this safe harbor.

## Rewards

Pulsar Framework does not currently operate a paid bug bounty program. Recognition is provided via:

- Public acknowledgement in the security advisory (with consent)
- Listing in the `docs/security/acknowledgements.md` hall of fame
- A permanent contributor role on relevant community channels

A formal bug bounty program may be launched after the 1.0.0 release.

## Cryptographic Primitives

The framework relies exclusively on audited cryptographic primitives provided by `ring`, `rustls`, and the standard Rust cryptography ecosystem. No custom primitives are shipped. Constant-time operations are enforced via the `subtle` crate, memory zeroing via `zeroize`, and secret wrapping via `secrecy`.

Side-channel hardening at the hardware level is a documented non-goal for the v1.0.0 scope; deployments requiring FIPS 140-2/140-3 validated hardware must pair Pulsar with an appropriate HSM or attested enclave.

## Supply Chain

- Daily `cargo audit` run via CI workflow (`.github/workflows/audit.yml`)
- `cargo-deny` gates every build for license, CVE, and duplicate-version violations
- Release artefacts are signed via GitHub OIDC through the Trusted Publisher workflow; provenance is attached as SLSA metadata
- No vendored dependencies at build time; all dependencies pulled from crates.io with pinned checksums in `Cargo.lock`
